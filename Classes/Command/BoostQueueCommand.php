<?php

declare(strict_types = 1);

namespace SFC\Staticfilecache\Command;

use Exception;
use GuzzleHttp\Psr7\Uri;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use SFC\Staticfilecache\Cache\IdentifierBuilder;
use SFC\Staticfilecache\Domain\Repository\QueueRepository;
use SFC\Staticfilecache\Event\PoolEvent;
use SFC\Staticfilecache\Service\ClientService;
use SFC\Staticfilecache\Service\ConfigurationService;
use SFC\Staticfilecache\Service\QueueService;
use SFC\Staticfilecache\Service\RemoveService;
use SFC\Staticfilecache\Service\SystemLoadService;
use Spatie\Async\Pool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use function count;
use function json_decode;
use function json_encode;

class BoostQueueCommand extends AbstractCommand implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    protected QueueRepository $queueRepository;
    protected QueueService $queueService;
    protected ClientService $clientService;
    protected Pool $pool;
    protected SystemLoadService $systemLoadService;
    protected int $actualConcurrency = 1;
    protected int $concurrency = 1;
    protected SymfonyStyle $io;
    protected bool $hasPool = false;
    protected string $user;
    protected string $pass;
    protected bool $stop = false;
    protected $handler;
    protected RemoveService $removeService;
    protected IdentifierBuilder $identifierBuilder;
    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(
        QueueRepository      $queueRepository,
        QueueService         $queueService,
        SystemLoadService    $systemLoadService,
        ClientService        $clientService,
        ConfigurationService $configurationService,
        RemoveService        $removeService,
        IdentifierBuilder    $identifierBuilder,
        EventDispatcherInterface $eventDispatcher
    )
    {
        $this->queueRepository = $queueRepository;
        $this->queueService = $queueService;
        $this->systemLoadService = $systemLoadService;
        $this->clientService = $clientService;
        $this->concurrency = (int)$configurationService->get('concurrency');
        $this->removeService = $removeService;
        $this->identifierBuilder = $identifierBuilder;
        $this->eventDispatcher = $eventDispatcher;

        $this->user = trim($configurationService->get('user') ?: '');
        $this->pass = trim($configurationService->get('pass') ?: '');

        parent::__construct('staticfilecache:boostQueue');
    }

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Run (work on) the cache boost queue. Call this task every 5 minutes.')
            ->addOption('avoid-cleanup', null, InputOption::VALUE_NONE, 'Avoid the cleanup of the queue items')
            ->addOption('limit-items', null, InputOption::VALUE_REQUIRED, 'Limit the items that are crawled. 0 => all', 1000)
            ->addOption('concurrency', null, InputOption::VALUE_OPTIONAL, 'If concurrent mode is enabled spawns up to N-threads');
    }

    protected function registerSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $signals = [SIGINT /*, SIGQUIT, SIGHUP, SIGTERM, */];
        $setStop = function()  {
            $message = 'Received signal, stopping worker';
            $this->io->warning($message);
            $this->logger->warning($message);
            $this->stop = true;
        };
        foreach ($signals as $signal) {
            $this->io->note('Signal ' . $signal . ' registered');
            pcntl_signal($signal, $setStop);
        }
    }

    protected function isProcessRunning(string $identifier): ?bool
    {
        if (!isset($GLOBALS['argv']) || !in_array($identifier, $GLOBALS['argv'], true)) {
            return null;
        }

        $list = [];
        exec('find /proc -mindepth 2 -maxdepth 2 -name cmdline -print0|xargs  -0 -n1', $list);
        $c = 0;
        foreach ($list as $file) {
            if (file_exists($file) && is_readable($file)) {
                $c += preg_match('/' . $identifier . '/', file_get_contents($file));
            }
        }

        return $c > 1;
    }

    /**
     * @return int
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     * @throws \Exception
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->io = $io;

        $isProcessRunning = $this->isProcessRunning('staticfilecache:boostQueue');
        if ($isProcessRunning) {
            $io->isVerbose() && $io->error('Process already running');
            return Command::FAILURE;
        }
        if (null === $isProcessRunning) {
            $warning = 'Can not check if the process already runs';
            $io->isVerbose() && $io->note($warning);
            $this->logger->warning($warning);
        }

        $this->registerSignals();

        // reset picked items as we assume this command is the master
        $io->note('Reset items which are picked by a process and were not done for whatever reason');
        $entries = $this->queueRepository->findStatistical();
        while ($row = $entries->fetchAssociative()) {
            $row['call_date'] = 0;
            $this->queueRepository->update($row);
        }

        if ($input->getOption('concurrency')) {
            $this->concurrency = (int)$input->getOption('concurrency');
        }

        if ($this->hasAsyncAbility()) {
            $this->createPool();
            if ($this->hasPool) {
                if ($this->systemLoadService->enabled()) {
                    $io->note('Ability to lower/raise concurrency enabled, up to ' . $this->concurrency . ' child processes');
                } else {
                    $io->note('Ability to lower/raise concurrency is not available');
                    $this->actualConcurrency = $this->concurrency;
                }
            }
        } else {
            $io->note('Async ability not available');
        }

        if (!(bool)$input->getOption('avoid-cleanup')) {
            $this->cleanupQueue($io);
        }

        $limit = (int)$input->getOption('limit-items');
        $limit = $limit > 0 ? $limit : 5000;
        $rows = $this->queueRepository->findOpen($limit);

        $io->progressStart(count($rows));
        foreach ($rows as $runEntry) {
            $runEntry['call_date'] = time();
            $this->queueRepository->update($runEntry);

            try {
                $uri = new Uri($runEntry['url']);
            } catch (Exception $exception) {
                $runEntry['error'] = $exception->getMessage();
                $this->queueRepository->update($runEntry);
                continue;
            }

            if (!$this->hasPool) {
                $code = $this->queueService->runSingleRequest($runEntry);
                $this->handleCodeOnRunEntry($code,  $runEntry);
                $this->io->progressAdvance();
                if ($this->stop) {
                    break;
                }
                continue;
            }

            $options = $this->clientService->getOptions($uri->getHost());
            if ($this->user) {
                $options['auth'] = [
                    $this->user, $this->pass
                ];
            }
            $client = $this->clientService->getCallableClient($options);
            $this->queueService->removeFromCache($runEntry);
            $url = (string)$uri;

            $this->feedPool(
                static function () use ($client, $url, $runEntry): string {
                    /** @noinspection JsonEncodingApiUsageInspection */
                    return json_encode([
                       'code' => $client->get($url, ['http_errors' => false])->getStatusCode(),
                       'runEntry' => $runEntry,
                   ]);
                },
                function (string $pack): string {
                    // if signal to stop was received clear the pool
                    if ($this->stop) {
                        $this->pool->stop();
                        foreach ($this->pool->getInProgress() as $runnable) {
                            $this->pool->markAsFailed($runnable);
                        }
                        $this->pool->wait();
                        $message = 'Stopped pool';
                        $this->logger->emergency($message);
                        return $message;
                    }

                    // this should not get any further?
                    if ($this->systemLoadService->enabled()) {
                        if ($this->systemLoadService->loadExceeded()) {
                            $this->lowerPoolConcurrency();
                            $this->io->isVeryVerbose() && $this->io->note('Waiting some seconds');
                            $this->systemLoadService->wait();
                        } else {
                            $this->raisePoolConcurrency();
                        }
                    }

                    if ($this->hasPool) {
                        $this->pool->notify();
                    }

                    $this->dispatchPoolEvent();

                    /** @noinspection JsonEncodingApiUsageInspection */
                    $parts = json_decode($pack, true);
                    $message = '';
                    if ($parts) {
                        $code = $parts['code'] ?: 900;
                        $this->handleCodeOnRunEntry($code, $parts['runEntry']);
                        $message = $parts['runEntry']['url'] . ' returned status code ' . $parts['code'];
                    }

                    $this->io->progressAdvance();
                    return $message;
                },
                $runEntry
            );
        }

        if ($this->hasPool) {
            $this->pool->wait();
        }

        $io->progressFinish();
        $io->success(count($rows) . ' items are done (perhaps not all are processed).');

        $this->dispatchPoolEvent();

        return Command::SUCCESS;
    }

    /**
     * @throws \Exception
     */
    protected function handleCodeOnRunEntry(int $code, array $runEntry): void
    {
        $this->queueService->setResult($runEntry, $code);

        // warmup done, all right
        if ($code < 299) {
            return;
        }

        // 3xx 4xx we do no keep the cache file in FS
        if ($code < 500) {
            $this->removeFromFileSystem($runEntry['url']);
            return;
        }

        // write a message to the database we can see later on
        $runEntry['error'] = 'HTTP return code ' . $code . ' received';
        $this->queueRepository->update($runEntry);
    }

    /**
     * @throws \Exception
     */
    protected function removeFromFileSystem(string $url): void
    {
        $fileName = $this->identifierBuilder->getFilepath($url);
        $path = dirname($fileName);
        $this->removeService->removeFilesFromDirectoryAndDirectoryItselfIfEmpty($path, $this->io);
    }

    protected function dispatchPoolEvent(): void
    {
        $this->eventDispatcher->dispatch(new PoolEvent([
            'actualConcurrency' => $this->actualConcurrency
        ]));
    }

    protected function hasAsyncAbility(): bool
    {
        /** @noinspection ClassConstantCanBeUsedInspection */
        return class_exists('\Spatie\Async\Pool');
    }

    protected function createPool(): void
    {
        if ($this->concurrency > 1 && $this->hasAsyncAbility()) {
            $this->pool = Pool::create();
            $this->pool->concurrency($this->actualConcurrency);
            $this->pool->timeout(120);
            $this->hasPool = true;
            $this->io->note('Created async pool');
        }
    }

    protected function feedPool(callable $callable, callable $callback, array $runEntry): void
    {
        $this->pool->add($callable)->then(
            function (string $output) use ($callback) {
                $this->io->isVeryVerbose() && $this->io->note($output);
                $callbackOutput = $callback($output);
                $this->io->isVeryVerbose() && $this->io->note($callbackOutput);
            }
        )->catch(
            function (Throwable $exception) use ($runEntry) {
                if ($exception->getMessage()) {
                    $this->io->error($exception->getMessage());
                    $runEntry['error'] = $exception->getMessage();
                    $this->queueRepository->update($runEntry);
                }
            }
        )->timeout(
            function () use ($runEntry) {
                $message = 'Timeout in boostqueue';
                $this->io->error($message);
                $runEntry['error'] = $message;
                $this->queueRepository->update($runEntry);
            }
        );
    }

    protected function lowerPoolConcurrency(): bool
    {
        if ($this->actualConcurrency < 2) {
            return false;
        }
        $this->actualConcurrency--;
        $this->io->isVerbose() && $this->io->note('Actual concurrency lowered to ' . $this->actualConcurrency);
        $this->pool->concurrency($this->actualConcurrency);
        return true;
    }

    protected function raisePoolConcurrency(): bool
    {
        if ($this->actualConcurrency >= $this->concurrency) {
            return false;
        }

        $this->actualConcurrency++;
        $this->io->isVerbose() && $this->io->note('Actual concurrency raised to ' . $this->actualConcurrency);
        $this->pool->concurrency($this->actualConcurrency);
        return true;
    }

    protected function cleanupQueue(SymfonyStyle $io): void
    {
        $rows = $this->queueRepository->findOld();
        foreach ($rows as $row) {
            $this->queueRepository->delete(['uid' => $row['uid']]);
        }

        $io->success(count($rows) . ' items are removed.');
    }
}
