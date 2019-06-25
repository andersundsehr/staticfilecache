<?php

/**
 * StaticFileCache.
 */

declare(strict_types = 1);

namespace SFC\Staticfilecache;

use SFC\Staticfilecache\Cache\UriFrontend;
use SFC\Staticfilecache\Service\CacheService;
use SFC\Staticfilecache\Service\ConfigurationService;
use SFC\Staticfilecache\Service\DateTimeService;
use SFC\Staticfilecache\Service\TagService;
use SFC\Staticfilecache\Service\UriService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * StaticFileCache.
 */
class StaticFileCache extends StaticFileCacheObject
{
    /**
     * Configuration of the extension.
     *
     * @var ConfigurationService
     */
    protected $configuration;

    /**
     * Cache.
     *
     * @var UriFrontend
     */
    protected $cache;

    /**
     * Cache.
     *
     * @var Dispatcher
     */
    protected $signalDispatcher;

    /**
     * Constructs this object.
     */
    public function __construct()
    {
        parent::__construct();
        try {
            $this->cache = GeneralUtility::makeInstance(CacheService::class)->get();
        } catch (\Exception $exception) {
            $this->logger->error('Problems getting the cache: ' . $exception->getMessage());
        }
        $this->signalDispatcher = GeneralUtility::makeInstance(Dispatcher::class);
        $this->configuration = GeneralUtility::makeInstance(ConfigurationService::class);
    }

    /**
     * Get the current object.
     *
     * @return StaticFileCache
     */
    public static function getInstance()
    {
        return GeneralUtility::makeInstance(self::class);
    }

    /**
     * Check if the SFC should create the cache.
     *
     * @param TypoScriptFrontendController $pObj        The parent object
     * @param int                          $timeOutTime The timestamp when the page times out
     */
    public function insertPageInCache(TypoScriptFrontendController $pObj, int $timeOutTime = 0)
    {
        $isStaticCached = false;

        $uri = GeneralUtility::makeInstance(UriService::class)->getUri();

        // Signal: Initialize variables before starting the processing.
        $preProcessArguments = [
            'frontendController' => $pObj,
            'uri' => $uri,
        ];
        $preProcessArguments = $this->dispatch('preProcess', $preProcessArguments);
        $uri = $preProcessArguments['uri'];

        // cache rules
        $ruleArguments = [
            'frontendController' => $pObj,
            'uri' => $uri,
            'explanation' => [],
            'skipProcessing' => false,
        ];
        $ruleArguments = $this->dispatch('cacheRule', $ruleArguments);
        $explanation = (array)$ruleArguments['explanation'];

        if (!$ruleArguments['skipProcessing']) {
            $timeOutTime = $this->calculateTimeout($timeOutTime, $pObj);

            // Don't continue if there is already an existing valid cache entry and we've got an invalid now.
            // Prevents overriding if a logged in user is checking the page in a second call
            // see https://forge.typo3.org/issues/67526
            if (!empty($explanation) && $this->hasValidCacheEntry($uri)) {
                return;
            }

            $tagService = GeneralUtility::makeInstance(TagService::class);

            // The page tag pageId_NN is included in $pObj->pageCacheTags
            $cacheTags = $tagService->getTags();
            $cacheTags[] = 'sfc_pageId_' . $pObj->page['uid'];
            $cacheTags[] = 'sfc_domain_' . \str_replace('.', '_', \parse_url($uri, PHP_URL_HOST));

            // This is supposed to have "&& !$pObj->beUserLogin" in there as well
            // This fsck's up the ctrl-shift-reload hack, so I pulled it out.
            if (empty($explanation)) {
                $content = $pObj->content;
                if ($this->configuration->isBool('showGenerationSignature')) {
                    $content .= "\n<!-- cached statically on: " . $this->formatTimestamp((new DateTimeService())->getCurrentTime()) . ' -->';
                    $content .= "\n<!-- expires on: " . $this->formatTimestamp($timeOutTime) . ' -->';
                }

                // Signal: Process content before writing to static cached file
                $contentArguments = [
                    'frontendController' => $pObj,
                    'uri' => $uri,
                    'content' => $content,
                    'timeOutSeconds' => $timeOutTime - (new DateTimeService())->getCurrentTime(),
                ];
                $contentArguments = $this->dispatch(
                    'processContent',
                    $contentArguments
                );
                $content = $contentArguments['content'];
                $timeOutSeconds = $contentArguments['timeOutSeconds'];
                $uri = $contentArguments['uri'];
                $isStaticCached = true;

                $tagService->send();
            } else {
                $cacheTags[] = 'explanation';
                $content = $explanation;
                $timeOutSeconds = 0;
            }

            // create cache entry
            $this->cache->set($uri, $content, $cacheTags, $timeOutSeconds);
        }

        // Signal: Post process (no matter whether content was cached statically)
        $postProcessArguments = [
            'frontendController' => $pObj,
            'uri' => $uri,
            'isStaticCached' => $isStaticCached,
        ];
        $this->dispatch('postProcess', $postProcessArguments);
    }

    /**
     * Calculate timeout
     *
     * @param int $timeOutTime
     * @param TypoScriptFrontendController $tsfe
     * @return int
     */
    protected function calculateTimeout(int $timeOutTime, TypoScriptFrontendController $tsfe): int
    {
        if (!\is_array($tsfe->page)) {
            $this->logger->warning('TSFE to not contains a valid page record?! Please check: https://github.com/lochmueller/staticfilecache/issues/150');
            return $timeOutTime;
        }
        if (0 === $timeOutTime) {
            $timeOutTime = $tsfe->get_cache_timeout();
        }
        // If page has a endtime before the current timeOutTime, use it instead:
        if ($tsfe->page['endtime'] > 0 && $tsfe->page['endtime'] < $timeOutTime) {
            $timeOutTime = $tsfe->page['endtime'];
        }
        return (int)$timeOutTime;
    }

    /**
     * Format the given timestamp.
     *
     * @param int $timestamp
     *
     * @return string
     */
    protected function formatTimestamp($timestamp): string
    {
        return \strftime($this->configuration->get('strftime'), $timestamp);
    }

    /**
     * Determines whether the given $uri has a valid cache entry.
     *
     * @param string $uri
     *
     * @return bool is available and valid
     */
    protected function hasValidCacheEntry($uri): bool
    {
        $entry = $this->cache->get($uri);

        return false !== $entry &&
            empty($entry['explanation']) &&
            $entry['expires'] >= (new DateTimeService())->getCurrentTime();
    }

    /**
     * Call Dispatcher.
     *
     * @param string $signalName
     * @param array  $arguments
     *
     * @return mixed
     */
    protected function dispatch(string $signalName, array $arguments)
    {
        try {
            return $this->signalDispatcher->dispatch(__CLASS__, $signalName, $arguments);
        } catch (\Exception $exception) {
            $this->logger->error('Problems by calling signal: ' . $exception->getMessage() . ' / ' . $exception->getFile() . ':' . $exception->getLine());
            return $arguments;
        }
    }
}
