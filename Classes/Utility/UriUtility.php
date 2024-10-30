<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Utility;

use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\PageLinkBuilder;
use TYPO3\CMS\Frontend\Typolink\UnableToLinkException;

class UriUtility
{
    /**
     * @param int $pageUid
     * @return array
     * @throws \TYPO3\CMS\Core\Exception\SiteNotFoundException
     */
    public function generate(int $pageUid): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $siteFinder->getSiteByPageId($pageUid);

        $linkDetails = ['pageuid' => $pageUid];
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);

        $pageLinkBuilder = GeneralUtility::makeInstance(PageLinkBuilder::class, $cObj);
        $base = $site->getBase();
        $urls = [];
        foreach ($site->getLanguages() as $language) {
            $conf = [
                'language' => $language->getLanguageId(),
                'forceAbsoluteUrl' => true,
            ];
            try {
                $uriParts = $pageLinkBuilder->build($linkDetails, '', '', $conf);
            } catch (UnableToLinkException $e) {
                continue;
            }
            if ($uriParts) {
                $uriFromParts = new Uri($uriParts[0]);
                $uri = $base->withPath($uriFromParts->getPath());
                $urls[] = (string)$uri;
            }
        }
        return $urls;
    }
}
