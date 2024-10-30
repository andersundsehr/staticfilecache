<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Service;

use SFC\Staticfilecache\Domain\Repository\CacheRepository;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use SFC\Staticfilecache\Command\BoostQueueCommand;
use SFC\Staticfilecache\Domain\Repository\QueueRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Queue service.
 *
 * @see BoostQueueCommand
 */
class QueueService extends AbstractService
{
    public const PRIORITY_HIGH = 2000;
    public const PRIORITY_MEDIUM = 1000;
    public const PRIORITY_LOW = 0;

    /**
     * Queue repository.
     */
    protected QueueRepository $queueRepository;

    protected ConfigurationService $configurationService;

    protected ClientService $clientService;

    protected CacheService $cacheService;

    /**
     * QueueService constructor.
     */
    public function __construct(QueueRepository $queueRepository, ConfigurationService $configurationService, ClientService $clientService, CacheService $cacheService)
    {
        $this->queueRepository = $queueRepository;
        $this->configurationService = $configurationService;
        $this->clientService = $clientService;
        $this->cacheService = $cacheService;
    }

    /**
     * Add identifiers to Queue.
     */
    public function addIdentifiers(array $identifiers, int $overridePriority = self::PRIORITY_LOW): void
    {
        $identifiers = GeneralUtility::makeInstance(CacheRepository::class)->findUrlsByIdentifiers($identifiers);
        $increased = array_flip(array_column($this->queueRepository->increaseCachePriorityByIdentifiers(array_keys($identifiers)), 'identifier'));

        $additions = [];
        foreach ($identifiers as $identifier => $url) {
            if (!isset($increased[$identifier])) {
                $additions[] = [
                    'identifier' => $identifier,
                    'url' => $url,
                    'page_uid' => 0,
                    'invalid_date' => time(),
                    'call_result' => '',
                    'cache_priority' => $overridePriority,
                    'error' => null,
                    'call_date' => 0,
                    'retries' => 0,
                ];
            }
        }
        if ($additions) {
            $this->logger->debug('SFC Queue add', array_column($additions, 'url'));
            $cache = $this->cacheService->get();
            foreach ($additions as &$addition) {
                try {
                    $infos = $cache->get($addition['url']);
                    if (isset($infos['priority'])) {
                        $addition['cache_priority'] = (int)$infos['priority'];
                    }
                } catch (\Exception $exception) {
                }
            }
            $this->queueRepository->bulkInsert($additions);
        }
    }

    /**
     * Run a single request with guzzle.
     *
     * @throws NoSuchCacheException
     */
    public function runSingleRequest(array $runEntry): int
    {
        $this->configurationService->override('boostMode', '0');
        $cache = $this->cacheService->get();

        if ($cache->has($runEntry['url'])) {
            $cache->remove($runEntry['url']);
        }

        $this->logger->debug('SFC Queue run', $runEntry);

        $statusCode = $this->clientService->runSingleRequest($runEntry['url']);

        $data = [
            'call_date' => time(),
            'call_result' => $statusCode,
        ];

        if (200 !== $statusCode) {
            // Call the flush, if the page is not accessable
            $cache->flushByTag('pageId_'.$runEntry['page_uid']);
        }

        $this->queueRepository->update($data, ['uid' => (int) $runEntry['uid']]);
        $this->configurationService->reset('boostMode');
        return $statusCode;
    }

    public function removeFromCache($runEntry): void
    {
        $this->configurationService->override('boostMode', '0');
        $cache = $this->cacheService->get();

        if ($cache->has($runEntry['url'])) {
            $cache->remove($runEntry['url']);
        }

        $this->logger->debug('SFC Queue run', $runEntry);
    }

    public function setResult($runEntry, $statusCode): void
    {
        $this->configurationService->override('boostMode', '0');
        $cache = $this->cacheService->get();
        $data = [
            'error' => $runEntry['error'],
            'call_date' => time(),
            'call_result' => $statusCode,
        ];

        if (200 !== $statusCode) {
            // Call the flush, if the page is not accessable
            $cache->flushByTag('pageId_' . $runEntry['page_uid']);
        }

        $this->queueRepository->update($data, ['uid' => (int) $runEntry['uid']]);
        $this->configurationService->reset('boostMode');
    }
}
