<?php
/**
 * Cache commands
 *
 * @author  Tim Lochmüller
 */

namespace SFC\Staticfilecache\Command;

use SFC\Staticfilecache\QueueManager;
use SFC\Staticfilecache\Utility\CacheUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Cache commands
 */
class CacheCommandController extends AbstractCommandController
{

    /**
     * Remove the expired pages
     */
    public function removeExpiredPagesCommand()
    {
        CacheUtility::getInstance()->getCache()
            ->collectGarbage();
    }

    /**
     * Run the cache boost queue
     *
     * @param int $limitItems Limit the items that are crawled. 0 => all
     */
    public function runCacheBoostQueueCommand($limitItems = 0)
    {
        $queue = GeneralUtility::makeInstance(QueueManager::class);
        $queue->run($limitItems);
    }

    /**
     * Flush the cache
     * If the boost mode is active, all pages are recrawlt
     */
    public function flushCacheCommand()
    {
        CacheUtility::getInstance()->getCache()
            ->flush();
    }
}
