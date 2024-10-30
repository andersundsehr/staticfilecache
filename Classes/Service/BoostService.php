<?php

declare(strict_types=1);


namespace SFC\Staticfilecache\Service;

use Exception;
use GuzzleHttp\Psr7\Uri;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use SFC\Staticfilecache\Cache\IdentifierBuilder;
use SFC\Staticfilecache\Domain\Repository\QueueRepository;
use SFC\Staticfilecache\Event\PoolEvent;
use Spatie\Async\Pool;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class BoostService implements LoggerAwareInterface
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
    }

    public function process(int $concurrency, bool $avoidCleanup, int $limit, SymfonyStyle $io, callable $shouldStop = null): void
    {
        $this->io = $io;

        if ($concurrency) {
            $this->concurrency = $concurrency;
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

        $limit = $limit > 0 ? $limit : 5000;
        $rows = $this->queueRepository->findOpen($limit);

        $io->progressStart(count($rows));
        foreach ($rows as $runEntry) {
            if (!$this->stop && null !== $shouldStop && $shouldStop()) {
                $this->stop();
            }
            $runEntry['call_date'] = time();
            $runEntry['retries']++;
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
                function (string $pack) use ($shouldStop) : string {
                    if (!$this->stop && null !== $shouldStop && $shouldStop()) {
                        $this->stop();
                    }
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
                        $parts['runEntry']['error'] = null;
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

        if (!$avoidCleanup) {
            $this->cleanupQueue($io);
        }

        $this->dispatchPoolEvent();
    }

    public function stop(): void
    {
        $this->stop = true;
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
        $uids = $this->queueRepository->findOldUids();
        $io->progressStart(count($uids));
        foreach ($uids as $uid) {
            $this->queueRepository->delete(['uid' => $uid]);
            $io->progressAdvance();
        }
        $io->progressFinish();
        $io->success(count($uids) . ' items are removed.');
    }
}
