<?php
/**
 * Cache backend for static file cache
 *
 * @author  Tim Lochmüller
 */

namespace SFC\Staticfilecache\Cache;

use SFC\Staticfilecache\QueueManager;
use SFC\Staticfilecache\Utility\CacheUtility;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Cache backend for static file cache
 *
 * This cache handle the file representation of the cache and handle
 * - CacheFileName
 * - CacheFileName.gz
 *
 * @author Tim Lochmüller
 */
class StaticFileBackend extends AbstractBackend
{

    /**
     * Cache directory
     *
     * @var string
     */
    const CACHE_DIRECTORY = 'typo3temp/tx_staticfilecache/';

    /**
     * Saves data in the cache.
     *
     * @param string $entryIdentifier An identifier for this specific cache entry
     * @param string $data The data to be stored
     * @param array $tags Tags to associate with this cache entry
     * @param integer $lifetime Lifetime of this cache entry in seconds
     *
     * @return void
     * @throws \TYPO3\CMS\Core\Cache\Exception if no cache frontend has been set.
     * @throws \TYPO3\CMS\Core\Cache\Exception\InvalidDataException if the data is not a string
     */
    public function set($entryIdentifier, $data, array $tags = [], $lifetime = null)
    {
        $databaseData = [
            'created' => $GLOBALS['EXEC_TIME'],
            'expires' => ($GLOBALS['EXEC_TIME'] + $this->getRealLifetime($lifetime)),
        ];
        if (in_array('explanation', $tags)) {
            $databaseData['explanation'] = $data;
            parent::set($entryIdentifier, serialize($databaseData), $tags, $lifetime);
            return;
        }

        // call set in front of the generation, because the set method
        // of the DB backend also call remove
        parent::set($entryIdentifier, serialize($databaseData), $tags, $lifetime);

        $fileName = $this->getCacheFilename($entryIdentifier);
        $cacheDir = PathUtility::pathinfo($fileName, PATHINFO_DIRNAME);
        if (!is_dir($cacheDir)) {
            GeneralUtility::mkdir_deep($cacheDir);
        }

        CacheUtility::getInstance()->removeStaticFiles($entryIdentifier);

        // normal
        GeneralUtility::writeFile($fileName, $data);

        // gz
        if ($this->configuration->get('enableStaticFileCompression')) {
            $contentGzip = gzencode($data, $this->getCompressionLevel());
            if ($contentGzip) {
                GeneralUtility::writeFile($fileName . '.gz', $contentGzip);
            }
        }

        // htaccess
        $this->writeHtAccessFile($fileName, $lifetime);
    }

    /**
     * Write htaccess file
     *
     * @param string $originalFileName
     * @param string $lifetime
     */
    protected function writeHtAccessFile($originalFileName, $lifetime)
    {
        $sendCCHeader = (bool)$this->configuration->get('sendCacheControlHeader');
        $sendCCHeaderRedirectAfter = (bool)$this->configuration->get('sendCacheControlHeaderRedirectAfterCacheTimeout');
        if ($sendCCHeader || $sendCCHeaderRedirectAfter) {
            $fileName = PathUtility::pathinfo($originalFileName, PATHINFO_DIRNAME) . '/.htaccess';
            $accessTimeout = $this->configuration->get('htaccessTimeout');
            $lifetime = $accessTimeout ? $accessTimeout : $this->getRealLifetime($lifetime);

            /** @var StandaloneView $renderer */
            $templateName = 'EXT:staticfilecache/Resources/Private/Templates/Htaccess.html';
            $renderer = GeneralUtility::makeInstance(StandaloneView::class);
            $renderer->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($templateName));
            $renderer->assignMultiple([
                'mode' => $accessTimeout ? 'A' : 'M',
                'lifetime' => $lifetime,
                'expires' => time() + $lifetime,
                'sendCacheControlHeader' => $sendCCHeader,
                'sendCacheControlHeaderRedirectAfterCacheTimeout' => $sendCCHeaderRedirectAfter,
            ]);

            GeneralUtility::writeFile($fileName, $renderer->render());
        }
    }

    /**
     * Get the cache folder for the given entry
     *
     * @param $entryIdentifier
     *
     * @return string
     */
    protected function getCacheFilename($entryIdentifier)
    {
        $urlParts = parse_url($entryIdentifier);
        $cacheFilename = GeneralUtility::getFileAbsFileName(self::CACHE_DIRECTORY . $urlParts['scheme'] . '/' . $urlParts['host'] . '/' . trim(
            $urlParts['path'],
            '/'
        ));
        $fileExtension = PathUtility::pathinfo(basename($cacheFilename), PATHINFO_EXTENSION);
        if (empty($fileExtension) || !GeneralUtility::inList($this->configuration->get('fileTypes'), $fileExtension)) {
            $cacheFilename = rtrim($cacheFilename, '/') . '/index.html';
        }
        return $cacheFilename;
    }

    /**
     * Loads data from the cache (DB).
     *
     * @param string $entryIdentifier An identifier which describes the cache entry to load
     *
     * @return mixed The cache entry's content as a string or FALSE if the cache entry could not be loaded
     */
    public function get($entryIdentifier)
    {
        if (!$this->has($entryIdentifier)) {
            return null;
        }
        return unserialize(parent::get($entryIdentifier), false);
    }

    /**
     * Checks if a cache entry with the specified identifier exists.
     *
     * @param string $entryIdentifier An identifier specifying the cache entry
     *
     * @return boolean TRUE if such an entry exists, FALSE if not
     */
    public function has($entryIdentifier)
    {
        return is_file($this->getCacheFilename($entryIdentifier)) || parent::has($entryIdentifier);
    }

    /**
     * Removes all cache entries matching the specified identifier.
     * Usually this only affects one entry but if - for what reason ever -
     * old entries for the identifier still exist, they are removed as well.
     *
     * @param string $entryIdentifier Specifies the cache entry to remove
     *
     * @return boolean TRUE if (at least) an entry could be removed or FALSE if no entry was found
     */
    public function remove($entryIdentifier)
    {
        if (!$this->has($entryIdentifier)) {
            return false;
        }

        if ($this->isBoostMode()) {
            $this->getQueue()
                ->addIdentifier($entryIdentifier);
            return true;
        }

        CacheUtility::getInstance()->removeStaticFiles($entryIdentifier);
        return parent::remove($entryIdentifier);
    }

    /**
     * Removes all cache entries of this cache.
     *
     * @return void
     */
    public function flush()
    {
        if ((boolean)$this->configuration->get('clearCacheForAllDomains') === false) {
            $this->flushByTag('sfc_domain_' . str_replace('.', '_', GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY')));
            return;
        }

        if ($this->isBoostMode()) {
            $identifiers = $this->getIdentifiers();
            $queue = $this->getQueue();
            foreach ($identifiers as $item) {
                $queue->addIdentifier($item['identifier']);
            }
            parent::flush();
            return;
        }

        $absoluteCacheDir = GeneralUtility::getFileAbsFileName(self::CACHE_DIRECTORY);
        if (is_dir($absoluteCacheDir)) {
            $tempAbsoluteCacheDir = rtrim($absoluteCacheDir, '/') . '_' . GeneralUtility::milliseconds() . '/';
            rename($absoluteCacheDir, $tempAbsoluteCacheDir);
        }
        parent::flush();
        if (isset($tempAbsoluteCacheDir)) {
            GeneralUtility::rmdir($tempAbsoluteCacheDir, true);
        }
    }

    /**
     * Removes all cache entries of this cache which are tagged by the specified tag.
     *
     * @param string $tag The tag the entries must have
     *
     * @return void
     */
    public function flushByTag($tag)
    {
        $identifiers = $this->findIdentifiersByTag($tag);
        if ($this->isBoostMode()) {
            $queue = $this->getQueue();
            foreach ($identifiers as $identifier) {
                $queue->addIdentifier($identifier);
            }
            return;
        }
        $identifiers = $this->findIdentifiersByTag($tag);
        foreach ($identifiers as $identifier) {
            CacheUtility::getInstance()->removeStaticFiles($identifier);
        }
        parent::flushByTag($tag);
    }

    /**
     * Does garbage collection
     *
     * @return void
     */
    public function collectGarbage()
    {
        $cacheEntryIdentifiers = $this->getIdentifiers('expires < ' . $GLOBALS['EXEC_TIME']);
        parent::collectGarbage();
        foreach ($cacheEntryIdentifiers as $row) {
            CacheUtility::getInstance()->removeStaticFiles($row['identifier']);
        }
    }

    /**
     * Get queue manager
     *
     * @return QueueManager
     */
    protected function getQueue()
    {
        return GeneralUtility::makeInstance(QueueManager::class);
    }

    /**
     * Check if boost mode is active and if the calls are not part of the worker
     *
     * @return bool
     */
    protected function isBoostMode()
    {
        return (boolean)$this->configuration->get('boostMode') && !defined('SFC_QUEUE_WORKER');
    }

    /**
     * Get the cache identifiers
     *
     * @param string $where
     * @return array
     */
    protected function getIdentifiers($where = '1=1')
    {
        // @todo DB Migration for 8.x
        return (array)$this->getDatabaseConnection()
            ->exec_SELECTgetRows('identifier', $this->cacheTable, $where);
    }

    /**
     * Get the database connection
     *
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
