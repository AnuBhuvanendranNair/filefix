<?php

declare(strict_types=1);

namespace Anubit\Filefix\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

class LogRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findRecent(int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_filefix_log');
        $qb->getRestrictions()->removeAll();

        return $qb
            ->select('*')
            ->from('tx_filefix_log')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countTotal(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_filefix_log');
        $qb->getRestrictions()->removeAll();

        return (int)$qb->count('uid')->from('tx_filefix_log')->executeQuery()->fetchOne();
    }
}
