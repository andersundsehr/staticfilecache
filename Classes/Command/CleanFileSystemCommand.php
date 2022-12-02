<?php

namespace SFC\Staticfilecache\Command;

use SFC\Staticfilecache\Cache\IdentifierBuilder;
use SFC\Staticfilecache\Domain\Repository\CacheRepository;
use SFC\Staticfilecache\Service\CacheService;
use SFC\Staticfilecache\Service\RemoveService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The purpose of this command is to remove files from the filesystem which have no counterpart on the cache table, because they are orphaned
 * and would stay on the system until the whole folder is flushed by a "clear all"
 */
class CleanFileSystemCommand extends AbstractCommand
{
    protected IdentifierBuilder $identifierBuilder;
    protected CacheRepository $cacheRepository;
    protected RemoveService $removeService;
    protected int $absoluteCacheDirLength;
    protected int $appendSlash;
    protected string $absoluteCacheDir;
    protected int $stripPort;
    protected int $delete;

    public function __construct(IdentifierBuilder $identifierBuilder, CacheRepository $cacheRepository, RemoveService $removeService)
    {
        $this->identifierBuilder = $identifierBuilder;
        $this->cacheRepository = $cacheRepository;
        $this->removeService = $removeService;
        parent::__construct('staticfilecache:cleanFileSystem');
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Checks files on the filesystem cache directory for their table counterpart');
        $this->addOption('strip-port', null, InputOption::VALUE_OPTIONAL, 'Remove the port if the number matches', 443);
        $this->addOption('append-slash', null, InputOption::VALUE_OPTIONAL, 'Add a slash to the end of your URL', 1);
        $this->addOption('delete', null, InputOption::VALUE_OPTIONAL, 'Do not just print but also delete the orphan file from FS', 0);
        $this->absoluteCacheDir = GeneralUtility::makeInstance(CacheService::class)->getAbsoluteBaseDirectory();
        $this->absoluteCacheDirLength = strlen($this->absoluteCacheDir);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->stripPort = filter_var($input->getOption('strip-port'), FILTER_VALIDATE_INT);
        $this->appendSlash = filter_var($input->getOption('append-slash'), FILTER_VALIDATE_INT);
        $this->delete = filter_var($input->getOption('delete'), FILTER_VALIDATE_INT);
        $this->scan($this->absoluteCacheDir, $output);
        return Command::SUCCESS;
    }

    protected function scan(string $directory, OutputInterface $output)
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new \DirectoryIterator($directory) as $item) {
            /** @var \DirectoryIterator $item */
            if ($item->isDir() && !$item->isDot()) {
                if (file_exists($item->getPathname() . '/index')) {
                    $url = $this->pathToUrl($item->getPathname() . ($this->appendSlash ? '/' : ''));
                    $hash = $this->identifierBuilder->hash($url);

                    if (!$this->cacheRepository->findUrlsByIdentifiers([$hash])) {
                        $output->writeln($url);
                        if ($this->delete) {
                            $this->removeService->removeFilesFromDirectoryAndDirectoryItselfIfEmpty($item->getPathname(), $output);

                        }
                    }
                }

                $this->scan($item->getPathname() . '/', $output);
            }
        }
    }

    protected function pathToUrl(string $getPathname): string
    {
        $part = substr($getPathname, $this->absoluteCacheDirLength);
        return $this->identifierBuilder->getUrl($part, $this->stripPort);
    }
}
