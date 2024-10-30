<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Hook;

use SFC\Staticfilecache\Service\CacheService;
use SFC\Staticfilecache\Service\ConfigurationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * DatamapHook.
 */
class DatamapHook extends AbstractHook
{
    /**
     * Check if the page is removed out of the SFC.
     * We drop the cache in this case.
     *
     */
    public function processDatamap_afterDatabaseOperations($status, $table, $id, $fieldArray, DataHandler $dataHandler): void
    {
        if ('pages' !== $table) {
            return;
        }
        if (!MathUtility::canBeInterpretedAsInteger($id)) {
            return;
        }

        $row = BackendUtility::getRecord($table, (int) $id);
        $allowSfc = (bool) $row['tx_staticfilecache_cache'];
        $hidden = isset($GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['disabled']) ? (bool) $row[$GLOBALS['TCA']['pages']['ctrl']['enablecolumns']['disabled']] : false;
        $deleted = isset($GLOBALS['TCA']['pages']['ctrl']['delete']) ? (bool) $row[$GLOBALS['TCA']['pages']['ctrl']['delete']] : false;
        $hideIfDefaultLanguage = GeneralUtility::hideIfDefaultLanguage($row['l18n_cfg']);
        if (!$allowSfc || $hidden || $deleted || $hideIfDefaultLanguage) {

            try {
                // Delete right now!! do not wait until queue is deleting this
                $configuration = GeneralUtility::makeInstance(ConfigurationService::class);
                $configuration->override('boostMode', '0');
                $cacheService = GeneralUtility::makeInstance(CacheService::class);
                $cacheService->get()->flushByTag('pageId_' . $row['l10n_parent'] ?: $id);
                $configuration->reset('boostMode');
            } catch (\Exception $ex) {
                return;
            }
        }
    }
}
