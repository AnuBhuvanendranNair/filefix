<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
        return $qb
            ->select('sf.uid', 'sf.identifier', 'sf.name', 'sf.size', 'sf.mime_type', 'sf.tstamp', 'sf.missing')
            ->orderBy('sf.identifier')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findAllUnusedFileUids(int $storageUid = 1, string $folderPrefix = '', string $extension = ''): array
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix, $extension);
        $rows = $qb->select('sf.uid')->executeQuery()->fetchAllAssociative();
        return array_column($rows, 'uid');
    }

    public function countUnusedFiles(int $storageUid = 1, string $folderPrefix = '', string $extension = ''): int
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix, $extension);
        return (int)$qb->count('sf.uid')->executeQuery()->fetchOne();
    }

    /**
     * Returns distinct file extensions (lowercase, sorted) from unused files in the given folder.
     * Always runs without extension filter so all available options are returned.
     */
    public function getAvailableExtensions(int $storageUid = 1, string $folderPrefix = ''): array
    {
        $qb = $this->buildUnusedFilesQuery($storageUid, $folderPrefix);
        $rows = $qb->select('sf.identifier')->executeQuery()->fetchAllAssociative();
        $exts = [];
        foreach ($rows as $row) {
            $ext = strtolower(pathinfo((string)$row['identifier'], PATHINFO_EXTENSION));
            if ($ext !== '') {
                $exts[$ext] = true;
            }
        }
        $exts = array_keys($exts);
        sort($exts);
        return $exts;
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

    private function buildUnusedFilesQuery(int $storageUid, string $folderPrefix, string $extension = ''): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();

        $qb->from('sys_file', 'sf')
            ->leftJoin('sf', 'sys_file_reference', 'sfr', 'sf.uid = sfr.uid_local AND sfr.deleted = 0')
            ->leftJoin('sf', 'sys_refindex', 'sri', "sri.ref_table = 'sys_file' AND sri.ref_uid = sf.uid AND sri.softref_key != ''")
            ->where(
                $qb->expr()->isNull('sfr.uid'),
                $qb->expr()->isNull('sri.hash'),
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
            $escaped = $conn->escapeLikeWildcards(strtolower($extension));
            $qb->andWhere($qb->expr()->like('sf.identifier', $qb->createNamedParameter('%.' . $escaped)));
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
