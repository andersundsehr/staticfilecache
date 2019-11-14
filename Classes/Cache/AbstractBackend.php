<?php
/**
 * General Cache functions for Static File Cache
 *
 * @author  Tim Lochmüller
 */

namespace SFC\Staticfilecache\Cache;

use SFC\Staticfilecache\Configuration;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * General Cache functions for Static File Cache
 *
 * @author Tim Lochmüller
 */
class AbstractBackend extends Typo3DatabaseBackend
{

    /**
     * The default compression level
     */
    const DEFAULT_COMPRESSION_LEVEL = 7;

    /**
     * Configuration
     *
     * @var Configuration
     */
    protected $configuration;

    /**
     * Constructs this backend
     *
     * @param string $context FLOW3's application context
     * @param array  $options Configuration options - depends on the actual backend
     */
    public function __construct($context, array $options = [])
    {
        parent::__construct($context, $options);
        $this->configuration = GeneralUtility::makeInstance(Configuration::class);
    }

    /**
     * Get compression level
     *
     * @return int
     */
    protected function getCompressionLevel()
    {
        $level = self::DEFAULT_COMPRESSION_LEVEL;
        if (isset($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'])) {
            $level = (int)$GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'];
        }
        if (!MathUtility::isIntegerInRange($level, 1, 9)) {
            $level = self::DEFAULT_COMPRESSION_LEVEL;
        }
        return $level;
    }

    /**
     * Get the real life time
     *
     * @param int $lifetime
     *
     * @return int
     */
    protected function getRealLifetime($lifetime)
    {
        if (is_null($lifetime)) {
            $lifetime = $this->defaultLifetime;
        }
        if ($lifetime === 0 || $lifetime > $this->maximumLifetime) {
            $lifetime = $this->maximumLifetime;
        }
        return $lifetime;
    }
}
