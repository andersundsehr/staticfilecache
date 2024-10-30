<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Command;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use SFC\Staticfilecache\Service\BoostService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BoostQueueCommand extends AbstractCommand implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    protected SymfonyStyle $io;
    protected BoostService $boostService;

    public function __construct(BoostService $boostService)
    {
        $this->boostService = $boostService;
        parent::__construct('staticfilecache:boostQueue');
    }

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        parent::configure();
        // @todo When compatibility is set to TYPO3 v11+ only, the description can be removed as it is defined in Services.yaml
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
            $this->boostService->stop();
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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
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

        $concurrency = (int)$input->getOption('concurrency');
        $avoidCleanup = (bool)$input->getOption('avoid-cleanup');
        $limit = (int)$input->getOption('limit-items');
        $this->boostService->process($concurrency, $avoidCleanup, $limit, $io);

        return Command::SUCCESS;
    }
}
