<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Cache;

use SFC\Staticfilecache\Exception;
use SFC\Staticfilecache\Service\CacheService;
use SFC\Staticfilecache\Service\ConfigurationService;
use SFC\Staticfilecache\StaticFileCacheObject;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * IdentifierBuilder.
 */
class IdentifierBuilder extends StaticFileCacheObject
{
    /**
     * Get the cache name for the given URI.
     *
     * @throws \Exception
     */
    public function getFilepath(string $requestUri): string
    {
        if (!$this->isValidEntryUri($requestUri)) {
            throw new \Exception('Invalid RequestUri as cache identifier: '.$requestUri, 2346782);
        }
        $urlParts = parse_url($requestUri);
        $pageIdentifier = [
            'scheme' => $urlParts['scheme'] ?? 'https',
            'host' => $urlParts['host'] ?? 'invalid',
            'port' => $urlParts['port'] ?? ('https' === $urlParts['scheme'] ? 443 : 80),
        ];
        $parts = [
            'pageIdent' => implode('_', $pageIdentifier),
            'path' => trim($urlParts['path'] ?? '', '/'),
            'index' => 'index',
        ];

        if (GeneralUtility::makeInstance(ConfigurationService::class)->isBool('rawurldecodeCacheFileName')) {
            $parts['path'] = rawurldecode($parts['path']);
        }

        $absoluteBasePath = GeneralUtility::makeInstance(CacheService::class)->getAbsoluteBaseDirectory();
        $resultPath = GeneralUtility::resolveBackPath($absoluteBasePath.implode('/', $parts));

        if (!str_starts_with($resultPath, $absoluteBasePath)) {
            throw new Exception('The generated filename "'.$resultPath.'" should start with the cache directory "'.$absoluteBasePath.'"', 123781);
        }

        return $resultPath;
    }

    public function getUrl(string $filePath, int $stripPort): string
    {
        $p = strpos($filePath, '_');
        $p2 = strpos($filePath, '_', $p + 1);
        $first = substr($filePath, 0, $p) . '://' . substr($filePath, $p + 1, $p2 - $p - 1);
        if ($stripPort && strpos($filePath, '_' . $stripPort)) {
            return $first . substr($filePath, $p2 + 1 + strlen((string)$stripPort));
        }

        return  $first . ':' . substr($filePath, $p2 + 1);
    }

    /**
     * Check if the $requestUri is a valid base for cache identifier.
     */
    public function isValidEntryIdentifier(string $identifier): bool
    {
        return preg_replace('/[a-z0-9]{64}/', '', $identifier) === '';
    }

    public function isValidEntryUri(string $requestUri): bool
    {
        if (false === GeneralUtility::isValidUrl($requestUri)) {
            return false;
        }
        $urlParts = parse_url($requestUri);
        $required = ['host', 'scheme'];
        foreach ($required as $item) {
            if (!isset($urlParts[$item]) || mb_strlen($urlParts[$item]) <= 0) {
                return false;
            }
        }

        return true;
    }

    public function hash(string $requestUri): string
    {
        return hash('sha256', $requestUri);
    }
}
