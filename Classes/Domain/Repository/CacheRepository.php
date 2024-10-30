<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Domain\Repository;

use SFC\Staticfilecache\Service\ConfigurationService;
use SFC\Staticfilecache\Service\DateTimeService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * CacheRepository.
 */
class CacheRepository extends AbstractRepository
{
    /**
     * Get the expired cache identifiers.
     */
    public function findExpiredIdentifiers(): array
    {
        $queryBuilder = $this->createQuery();
        $cacheIdentifiers = $queryBuilder->select('identifier')
            ->from($this->getTableName())
            ->where(
                $queryBuilder->expr()->lt(
                    'expires',
                    $queryBuilder->createNamedParameter((new DateTimeService())->getCurrentTime(), \PDO::PARAM_INT)
                )
            )
            ->groupBy('identifier')
            ->executeQuery()
            ->fetchFirstColumn();
        return $cacheIdentifiers;
    }

    /**
     * Get all the cache identifiers.
     */
    public function findAllIdentifiers(): array
    {
        $queryBuilder = $this->createQuery();
        $cacheIdentifiers = $queryBuilder->select('identifier')
            ->from($this->getTableName())
            ->groupBy('identifier')
            ->executeQuery()
            ->fetchFirstColumn();
        return $cacheIdentifiers;
    }

    /**
     * Get the table name.
     */
    protected function getTableName(): string
    {
        $prefix = 'cache_';
        $configuration = GeneralUtility::makeInstance(ConfigurationService::class);
        if ($configuration->isBool('renameTablesToOtherPrefix')) {
            $prefix = 'sfc_';
        }

        return $prefix . 'staticfilecache';
    }

    /**
     * @param array $identifiers
     * @return array<string, string>
     */
    public function findUrlsByIdentifiers(array $identifiers): array
    {
        if (!$identifiers) {
            return [];
        }

        $cacheIdentifiers = [];

        foreach (array_chunk($identifiers, 1000) as $chunk) {
            $queryBuilder = $this->createQuery();
            foreach ($chunk as &$identifier) {
                $identifier = $queryBuilder->createNamedParameter($identifier);
            }
            unset($identifier);

            $result = $queryBuilder->select('*')
                ->from($this->getTableName())
                ->where(
                    $queryBuilder->expr()->in('identifier', $chunk),
                )
                ->execute();

            while ($row = $result->fetchAssociative()) {
                $content = unserialize($row['content'], ['allowed_classes' => false]);
                $url = $content['url'] ?? '';
                if (!$url) {
                    continue;
                }

                $cacheIdentifiers[$row['identifier']] = $url;
            }
        }

        return $cacheIdentifiers;
    }
}
