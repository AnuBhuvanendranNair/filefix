<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Result;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\StorageRepository;

class FileCleanupService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * Files in sys_file (storage $storageUid) with zero active sys_file_reference records.
     * $folderPrefix filters to files whose identifier starts with that path (e.g. "/images/").
     */
    public function findUnusedFiles(
        int $storageUid = 1,
        string $folderPrefix = '',
        int $limit = 10,
        int $offset = 0,
        string $extension = ''
    ): array {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix, $extension);
        $qb
            ->select('sf.uid', 'sf.identifier', 'sf.name', 'sf.size', 'sf.mime_type', 'sf.tstamp', 'sf.missing')
            ->orderBy('sf.identifier')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        return $this->executeUnusedFilesQuery($qb)->fetchAllAssociative();
    }

    public function findAllUnusedFileUids(int $storageUid = 1, string $folderPrefix = '', string $extension = ''): array
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix, $extension);
        $rows = $this->executeUnusedFilesQuery($qb->select('sf.uid'))->fetchAllAssociative();
        return array_column($rows, 'uid');
    }

    public function countUnusedFiles(int $storageUid = 1, string $folderPrefix = '', string $extension = ''): int
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix, $extension);
        return (int)$this->executeUnusedFilesQuery($qb->count('sf.uid'))->fetchOne();
    }

    /**
     * Unused file count per extension (lowercase key) in the given folder, in one query.
     * Always runs without extension filter so all available options are returned.
     *
     * @return array<string, int>
     */
    public function getUnusedExtensionCounts(int $storageUid = 1, string $folderPrefix = ''): array
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix);
        $qb->select('sf.extension')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->groupBy('sf.extension');
        $rows = $this->executeUnusedFilesQuery($qb)->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $ext = strtolower((string)$row['extension']);
            $counts[$ext] = ($counts[$ext] ?? 0) + (int)$row['cnt'];
        }
        ksort($counts);
        return $counts;
    }

    /**
     * sys_file records where the physical file is missing (FAL marked missing=1).
     */
    public function findOrphanedSysFileRecords(int $storageUid = 1): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();

        return $qb
            ->select('uid', 'identifier', 'name', 'size', 'mime_type', 'tstamp')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('missing', $qb->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->orderBy('identifier')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findFilesByUids(array $uids): array
    {
        $uids = array_filter(array_map('intval', $uids));
        if (empty($uids)) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return $qb
            ->select('uid', 'identifier', 'name')
            ->from('sys_file')
            ->where($qb->expr()->in(
                'uid',
                $qb->createNamedParameter($uids, ArrayParameterType::INTEGER)
            ))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function getStoragePublicBase(int $storageUid = 1): string
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        $basePath = $storage ? ($storage->getConfiguration()['basePath'] ?? 'fileadmin') : 'fileadmin';
        return '/' . trim($basePath, '/');
    }

    public function getStorageBasePath(int $storageUid = 1): string
    {
        $storage = $this->storageRepository->findByUid($storageUid);
        if ($storage === null) {
            return rtrim(Environment::getPublicPath(), '/') . '/fileadmin';
        }
        $config   = $storage->getConfiguration();
        $basePath = ltrim($config['basePath'] ?? 'fileadmin', '/');
        return rtrim(Environment::getPublicPath(), '/') . '/' . $basePath;
    }

    /**
     * Delete physical files + their sys_file + sys_file_reference rows.
     *
     * @param int[]  $uids
     * @return array{0: int, 1: string[], 2: array<array{uid: int, identifier: string, name: string}>}
     */
    public function deleteFiles(array $uids, string $storageBasePath): array
    {
        $deleted      = 0;
        $errors       = [];
        $deletedFiles = [];
        $realBase     = realpath($storageBasePath);

        foreach ($uids as $rawUid) {
            $uid = (int)$rawUid;
            if ($uid <= 0) {
                continue;
            }

            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
            $qb->getRestrictions()->removeAll();
            $file = $qb->select('uid', 'identifier', 'name')
                ->from('sys_file')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();

            if (!$file) {
                continue;
            }

            $absolutePath = rtrim($storageBasePath, '/') . '/' . ltrim((string)$file['identifier'], '/');
            $realFile     = realpath($absolutePath);

            if ($realFile && $realBase && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
                $errors[] = $file['identifier'] . ': path rejected (security check)';
                continue;
            }

            if ($realFile && is_file($realFile) && !unlink($realFile)) {
                $errors[] = basename((string)$file['identifier']) . ': could not delete file';
                continue;
            }

            $this->removeSysFileRows($uid);
            $deleted++;
            $deletedFiles[] = [
                'uid'        => $uid,
                'identifier' => (string)$file['identifier'],
                'name'       => (string)$file['name'],
            ];
        }

        return [$deleted, $errors, $deletedFiles];
    }

    /**
     * Remove orphaned sys_file records (no physical file exists, so nothing to unlink).
     *
     * @param int[]  $uids
     * @return array{0: int, 1: string[]}
     */
    public function deleteOrphanedRecords(array $uids): array
    {
        $deleted = 0;

        foreach ($uids as $rawUid) {
            $uid = (int)$rawUid;
            if ($uid <= 0) {
                continue;
            }
            $this->removeSysFileRows($uid);
            $deleted++;
        }

        return [$deleted, []];
    }

    /**
     * Runs a query from buildUnusedFilesQuery(). On MySQL/MariaDB the sys_file indexes are
     * disabled: all of them start with "storage" (cardinality 1 on typical installs), so
     * the optimizer's range plan does one random row lookup per sys_file row, measured
     * about 4x slower than a full table scan.
     */
    private function executeUnusedFilesQuery(QueryBuilder $qb): Result
    {
        $connection = $this->connectionPool->getConnectionForTable('sys_file');
        $sql        = $qb->getSQL();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $from = 'FROM ' . $qb->quoteIdentifier('sys_file') . ' ' . $qb->quoteIdentifier('sf');
            $sql  = str_replace($from, $from . ' USE INDEX ()', $sql);
        }
        return $connection->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
    }

    private function buildUnusedFilesQuery(int $storageUid, string $folderPrefix, string $extension = ''): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();

        // Correlated NOT EXISTS for sys_file_reference, uncorrelated NOT IN for sys_refindex:
        // the NOT IN subquery is materialized once instead of one sys_refindex lookup per sys_file row.
        $referenceSubQuery = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $referenceSubQuery->getRestrictions()->removeAll();
        $referenceSubQuery->select('sfr.uid')
            ->from('sys_file_reference', 'sfr')
            ->where(
                $qb->expr()->eq('sfr.uid_local', $qb->quoteIdentifier('sf.uid')),
                $qb->expr()->eq('sfr.deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            );

        $softReferenceSubQuery = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $softReferenceSubQuery->getRestrictions()->removeAll();
        $softReferenceSubQuery->select('sri.ref_uid')
            ->from('sys_refindex', 'sri')
            ->where(
                $qb->expr()->eq('sri.ref_table', $qb->createNamedParameter('sys_file')),
                $qb->expr()->neq('sri.softref_key', $qb->createNamedParameter(''))
            );

        $qb->from('sys_file', 'sf')
            ->where(
                'NOT EXISTS (' . $referenceSubQuery->getSQL() . ')',
                $qb->quoteIdentifier('sf.uid') . ' NOT IN (' . $softReferenceSubQuery->getSQL() . ')',
                $qb->expr()->eq('sf.storage', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sf.missing', $qb->createNamedParameter(0, ParameterType::INTEGER))
            );

        $conn = $this->connectionPool->getConnectionForTable('sys_file');

        if ($folderPrefix !== '') {
            $normalized = '/' . trim($folderPrefix, '/') . '/';
            $escaped    = $conn->escapeLikeWildcards($normalized);
            $qb->andWhere($qb->expr()->like('sf.identifier', $qb->createNamedParameter($escaped . '%')));
        }

        if ($extension !== '') {
            $qb->andWhere($qb->expr()->eq('sf.extension', $qb->createNamedParameter(strtolower($extension))));
        }

        return $qb;
    }

    private function removeSysFileRows(int $uid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')
            ->delete('sys_file_reference', ['uid_local' => $uid]);

        $this->connectionPool->getConnectionForTable('sys_file')
            ->delete('sys_file', ['uid' => $uid]);
    }
}
