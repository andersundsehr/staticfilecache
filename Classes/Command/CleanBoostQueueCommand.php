<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Command;

use SFC\Staticfilecache\Cache\IdentifierBuilder;
use SFC\Staticfilecache\Domain\Repository\QueueRepository;
use SFC\Staticfilecache\Service\RemoveService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CleanBoostQueueCommand extends AbstractCommand
{
    protected QueueRepository $queueRepository;
    protected RemoveService $removeService;

    public function __construct(QueueRepository $queueRepository, RemoveService $removeService)
    {
        $this->queueRepository = $queueRepository;
        $this->removeService = $removeService;
        parent::__construct('staticfilecache:cleanBoostQueue');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('If an error occured in boostqueue those static files along with the queue entry get removed');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->queueRepository->findError();
        foreach ($rows as $row) {
            $identifierBuilder = GeneralUtility::makeInstance(IdentifierBuilder::class);
            $fileName = $identifierBuilder->getFilepath($row['url']);
            $output->isVerbose() && $output->writeln('Removing stale queue entry ' . $row['url']);
            $path = dirname($fileName);
            $this->removeService->removeFilesFromDirectoryAndDirectoryItselfIfEmpty($path, $output);
            $this->queueRepository->delete(['uid' => $row['uid']]);
        }

        return self::SUCCESS;
    }
}
