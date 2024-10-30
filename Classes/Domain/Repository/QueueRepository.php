<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Domain\Repository;

use Doctrine\DBAL\Result;
use SFC\Staticfilecache\Service\ConfigurationService;

/**
 * QueueRepository.
 */
class QueueRepository extends AbstractRepository
{
    protected ConfigurationService $configurationService;
    public function __construct(ConfigurationService $configurationService)
    {
        $this->configurationService = $configurationService;
    }

    /**
     * Find the entries for the worker.
     *
     * @param int $limit
     */
    public function findOpen(int $limit = 99999999): array
    {
        $queryBuilder = $this->createQuery();

        return $queryBuilder->select('*')
            ->from($this->getTableName())
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('call_date', 0),
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->isNull('error'),
                        $queryBuilder->expr()->lt('retries', $this->configurationService->getRetries()),
                    )
                )
            )
            ->setMaxResults($limit)
            ->orderBy('retries', 'asc')
            ->addOrderBy('cache_priority', 'desc')
            ->executeQuery()
            ->fetchAllAssociative()
        ;
    }

    /**
     * Find open by identnfier.
     *
     * @param string $identifier
     */
    public function countOpenByIdentifier($identifier): int
    {
        $queryBuilder = $this->createQuery();
        $where = $queryBuilder->expr()->and(
            $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
            $queryBuilder->expr()->eq('call_date', 0)
        );

        return (int) $queryBuilder->select('uid')
            ->from($this->getTableName())
            ->where($where)
            ->executeQuery()
            ->rowCount();
    }

    /**
     * @param array<string> $identifier
     * @return array<string>
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function increaseCachePriorityByIdentifiers(array $identifiers): array
    {
        $c = count($identifiers);
        if ($c === 0) {
            return [];
        }

        $queryBuilder = $this->createQuery();
        for ($i = 0; $i < $c; $i++) {
            $identifiers[$i] = $queryBuilder->quote($identifiers[$i]);
        }

        $where = $queryBuilder->expr()->andX(
            $queryBuilder->expr()->in('identifier', $identifiers)
        );
        $databasePlatform = $queryBuilder->getConnection()->getDatabasePlatform();
        $emptyString = str_repeat($databasePlatform->getStringLiteralQuoteCharacter(), 2);
        $queryBuilder->update($this->getTableName())
            ->set('call_result', $emptyString, false)
            ->set('error', null)
            ->set('retries', 0)
            ->set('cache_priority', 'cache_priority + 1', false, \PDO::PARAM_STMT)
            ->where($where)
            ->executeStatement();

        $queryBuilder = $this->createQuery();
        return $queryBuilder->select('*')
            ->from($this->getTableName())
            ->where($where)
            ->executeQuery()
            ->fetchAllAssociative() ?: [];
    }

    /**
     * Find old entries.
     * @return list<int>
     */
    public function findOldUids(): array
    {
        $queryBuilder = $this->createQuery();

        return $queryBuilder->select('uid')
            ->from($this->getTableName())
            ->where(
                $queryBuilder->expr()->isNull('error'),
                $queryBuilder->expr()->neq('call_result', $queryBuilder->quote('')),
            )
            ->executeQuery()
            ->fetchFirstColumn();
    }


    public function findError(): array
    {
        $queryBuilder = $this->createQuery();

        return $queryBuilder->select('*')
            ->from($this->getTableName())
            ->where(
                $queryBuilder->expr()->isNotNull('error'),
            )
            ->execute()
            ->fetchAllAssociative();
    }

    public function findByStatus(int $status): array
    {
        $queryBuilder = $this->createQuery();

        return $queryBuilder->select('*')
            ->from($this->getTableName())
            ->where(
                $queryBuilder->expr()->eq('call_result', $status)
            )
            ->execute()
            ->fetchAllAssociative();
    }

    public function findStatistical(): Result
    {
        $queryBuilder = $this->createQuery();
        return $queryBuilder->select('*')
            ->from($this->getTableName())
            ->orderBy('error', 'DESC')
            ->addOrderBy('cache_priority')
            ->addOrderBy('call_result')
            ->executeQuery();
    }

    /**
     * Get the table name.
     */
    protected function getTableName(): string
    {
        return 'tx_staticfilecache_queue';
    }
}
