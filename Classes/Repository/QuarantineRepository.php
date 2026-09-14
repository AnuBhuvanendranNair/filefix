<?php

declare(strict_types=1);

namespace Anubit\Filefix\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class QuarantineRepository
{
    public const TABLE = 'tx_filefix_quarantine';

    public const STATUS_CANDIDATE   = 'candidate';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_SKIPPED     = 'skipped';
    public const STATUS_RESTORED    = 'restored';
    public const STATUS_FLUSHED     = 'flushed';
    public const STATUS_FAILED      = 'failed';

    public const REASON_UNUSED  = 'unused_fal_file';
    public const REASON_MISSING = 'missing_physical_file';
    public const REASON_ORPHAN  = 'physical_orphan';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Insert candidate or update existing active candidate.
     * Dedup key: (storage_uid, identifier, reason, status=candidate).
     */
    public function upsertCandidate(array $data): int
    {
        $now = time();
        $qb  = $this->getQb();

        $existing = $qb
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('storage_uid', $qb->createNamedParameter((int)($data['storage_uid'] ?? 0), ParameterType::INTEGER)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter((string)($data['identifier'] ?? ''))),
                $qb->expr()->eq('reason', $qb->createNamedParameter((string)($data['reason'] ?? ''))),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CANDIDATE))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($existing) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->update(
                self::TABLE,
                [
                    'scan_id'              => (string)($data['scan_id'] ?? ''),
                    'size'                 => (int)($data['size'] ?? 0),
                    'sha1'                 => (string)($data['sha1'] ?? ''),
                    'mtime'                => (int)($data['mtime'] ?? 0),
                    'absolute_path_snapshot' => (string)($data['absolute_path_snapshot'] ?? ''),
                    'updated_at'           => $now,
                    'last_checked_at'      => $now,
                ],
                ['uid' => (int)$existing['uid']]
            );
            return (int)$existing['uid'];
        }

        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $conn->insert(self::TABLE, [
            'pid'                    => 0,
            'storage_uid'            => (int)($data['storage_uid'] ?? 0),
            'file_uid'               => (int)($data['file_uid'] ?? 0),
            'identifier'             => (string)($data['identifier'] ?? ''),
            'name'                   => (string)($data['name'] ?? ''),
            'mime_type'              => (string)($data['mime_type'] ?? ''),
            'size'                   => (int)($data['size'] ?? 0),
            'sha1'                   => (string)($data['sha1'] ?? ''),
            'mtime'                  => (int)($data['mtime'] ?? 0),
            'absolute_path_snapshot' => (string)($data['absolute_path_snapshot'] ?? ''),
            'quarantine_identifier'  => '',
            'reason'                 => (string)($data['reason'] ?? ''),
            'status'                 => self::STATUS_CANDIDATE,
            'scan_id'                => (string)($data['scan_id'] ?? ''),
            'created_at'             => $now,
            'updated_at'             => $now,
            'last_checked_at'        => $now,
            'quarantined_at'         => 0,
            'flushed_at'             => 0,
            'restored_at'            => 0,
            'metadata_json'          => $data['metadata_json'] ?? null,
            'error_message'          => null,
        ]);

        return (int)$conn->lastInsertId(self::TABLE);
    }

    public function findById(int $uid): ?array
    {
        $qb  = $this->getQb();
        $row = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    public function findByUids(array $uids): array
    {
        $uids = array_filter(array_map('intval', $uids));
        if (empty($uids)) {
            return [];
        }
        $qb = $this->getQb();
        return $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, ArrayParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findForList(array $filters, int $limit, int $offset): array
    {
        return $this->buildListQuery($filters)
            ->select('*')
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function countForList(array $filters): int
    {
        return (int)$this->buildListQuery($filters)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    public function findForFlush(array $filters, int $limit): array
    {
        $qb = $this->getQb()->select('*')->from(self::TABLE);

        if (!empty($filters['status'])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }
        if (!empty($filters['storage_uid'])) {
            $qb->andWhere($qb->expr()->eq('storage_uid', $qb->createNamedParameter((int)$filters['storage_uid'], ParameterType::INTEGER)));
        }
        if (!empty($filters['scan_id'])) {
            $qb->andWhere($qb->expr()->eq('scan_id', $qb->createNamedParameter($filters['scan_id'])));
        }
        if (!empty($filters['queued_before'])) {
            $qb->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter((int)$filters['queued_before'], ParameterType::INTEGER)));
        }

        return $qb->orderBy('created_at', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function updateRecord(int $uid, array $data): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $data, ['uid' => $uid]);
    }

    /**
     * Returns ['status' => ['count' => N, 'size' => N], ...]
     */
    public function getStatusCounts(array $filters = []): array
    {
        $rows = $this->buildListQuery($filters)
            ->select('status')
            ->addSelectLiteral('COUNT(uid) AS cnt', 'COALESCE(SUM(size), 0) AS total_size')
            ->groupBy('status')
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['status']] = [
                'count' => (int)$row['cnt'],
                'size'  => (int)$row['total_size'],
            ];
        }
        return $result;
    }

    /**
     * Identifiers already sitting in the quarantine table for this storage under the given
     * statuses (e.g. STATUS_QUARANTINED) — used by scans to avoid re-queuing them as new candidates.
     *
     * @return array<string, true>
     */
    public function getIdentifiersByStatus(int $storageUid, array $statuses): array
    {
        if (empty($statuses)) {
            return [];
        }
        $qb   = $this->getQb();
        $rows = $qb->select('identifier')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('storage_uid', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)),
                $qb->expr()->in('status', $qb->createNamedParameter($statuses, ArrayParameterType::STRING))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_flip(array_column($rows, 'identifier'));
    }

    public function deleteCandidateByIdentity(int $storageUid, string $identifier, string $reason): int
    {
        $qb = $this->getQb();
        return (int)$qb
            ->delete(self::TABLE)
            ->where(
                $qb->expr()->eq('storage_uid', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier)),
                $qb->expr()->eq('reason', $qb->createNamedParameter($reason)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_CANDIDATE))
            )
            ->executeStatement();
    }

    public function getAvailableStorageUids(): array
    {
        $rows = $this->getQb()
            ->select('storage_uid')
            ->from(self::TABLE)
            ->groupBy('storage_uid')
            ->orderBy('storage_uid')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_column($rows, 'storage_uid');
    }

    public function getAvailableScanIds(): array
    {
        $qb   = $this->getQb();
        $rows = $qb->select('scan_id')
            ->from(self::TABLE)
            ->where($qb->expr()->neq('scan_id', $qb->createNamedParameter('')))
            ->groupBy('scan_id')
            ->orderBy('scan_id', 'DESC')
            ->setMaxResults(50)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_column($rows, 'scan_id');
    }

    /**
     * Returns top-level directory → record count, based on filters (excluding the folder filter itself).
     * e.g. ['/Banner/' => 12, '/2023/' => 47]
     */
    public function getDirectoryCounts(array $filters = []): array
    {
        $qb = $this->buildListQuery($filters);
        $rows = $qb->select('identifier')->executeQuery()->fetchAllAssociative();

        $dirs = [];
        foreach ($rows as $row) {
            $id  = ltrim((string)$row['identifier'], '/');
            $pos = strpos($id, '/');
            $dir = $pos !== false ? '/' . substr($id, 0, $pos) . '/' : '/';
            $dirs[$dir] = ($dirs[$dir] ?? 0) + 1;
        }
        ksort($dirs);
        return $dirs;
    }

    /**
     * Hard-delete quarantine records by status list. Returns number deleted.
     */
    public function deleteByStatuses(array $statuses): int
    {
        if (empty($statuses)) {
            return 0;
        }
        $qb     = $this->getQb();
        $params = [];
        foreach ($statuses as $status) {
            $params[] = $qb->createNamedParameter((string)$status);
        }
        return (int)$qb
            ->delete(self::TABLE)
            ->where($qb->expr()->in('status', $params))
            ->executeStatement();
    }

    /**
     * Hard-delete all quarantine records. Returns number deleted.
     */
    public function deleteAll(): int
    {
        return (int)$this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->executeStatement('DELETE FROM ' . self::TABLE);
    }

    private function buildListQuery(array $filters): QueryBuilder
    {
        $qb = $this->getQb()->from(self::TABLE);

        if (!empty($filters['status'])) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filters['status'])));
        }
        if (!empty($filters['storage_uid'])) {
            $qb->andWhere($qb->expr()->eq('storage_uid', $qb->createNamedParameter((int)$filters['storage_uid'], ParameterType::INTEGER)));
        }
        if (!empty($filters['reason'])) {
            $qb->andWhere($qb->expr()->eq('reason', $qb->createNamedParameter($filters['reason'])));
        }
        if (!empty($filters['scan_id'])) {
            $qb->andWhere($qb->expr()->eq('scan_id', $qb->createNamedParameter($filters['scan_id'])));
        }
        if (!empty($filters['folder'])) {
            $conn    = $this->connectionPool->getConnectionForTable(self::TABLE);
            $escaped = $conn->escapeLikeWildcards('/' . ltrim((string)$filters['folder'], '/'));
            $qb->andWhere($qb->expr()->like('identifier', $qb->createNamedParameter($escaped . '%')));
        }

        return $qb;
    }

    private function getQb(): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return $qb;
    }
}
