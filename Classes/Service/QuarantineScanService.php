<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ParameterType;
use Anubit\Filefix\Repository\QuarantineRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

class QuarantineScanService
{
    private const SHA1_SIZE_LIMIT = 100 * 1024 * 1024; // 100 MB
    private const QUARANTINE_DIR  = '_filefix_quarantine';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FileCleanupService $fileCleanupService,
        private readonly QuarantineRepository $quarantineRepository,
    ) {}

    public function generateScanId(): string
    {
        return 'scan_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * Queue unused FAL files (in sys_file, no active sys_file_reference).
     */
    public function scanUnusedFiles(
        int    $storageUid,
        string $folderPrefix,
        int    $olderThanTimestamp,
        int    $limit,
        string $scanId,
        int    $level = 0 // 0 = all levels; otherwise cap folder depth below $folderPrefix
    ): array {
        $stats             = ['new' => 0, 'updated' => 0];
        $storageBasePath   = $this->fileCleanupService->getStorageBasePath($storageUid);
        $alreadyQuarantined = $this->quarantineRepository->getIdentifiersByStatus(
            $storageUid,
            [QuarantineRepository::STATUS_QUARANTINED]
        );

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $qb->select('sf.uid', 'sf.identifier', 'sf.name', 'sf.size', 'sf.mime_type', 'sf.tstamp')
            ->from('sys_file', 'sf')
            ->leftJoin('sf', 'sys_file_reference', 'sfr', 'sf.uid = sfr.uid_local AND sfr.deleted = 0')
            ->leftJoin('sf', 'sys_refindex', 'sri', "sri.ref_table = 'sys_file' AND sri.ref_uid = sf.uid AND sri.softref_key != ''")
            ->where(
                $qb->expr()->isNull('sfr.uid'),
                $qb->expr()->isNull('sri.hash'),
                $qb->expr()->eq('sf.storage', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sf.missing', $qb->createNamedParameter(0, ParameterType::INTEGER))
            );

        if ($folderPrefix !== '') {
            $normalized = '/' . trim($folderPrefix, '/') . '/';
            $conn       = $this->connectionPool->getConnectionForTable('sys_file');
            $escaped    = $conn->escapeLikeWildcards($normalized);
            $qb->andWhere($qb->expr()->like('sf.identifier', $qb->createNamedParameter($escaped . '%')));
        }

        if ($olderThanTimestamp > 0) {
            $qb->andWhere($qb->expr()->lt('sf.tstamp', $qb->createNamedParameter($olderThanTimestamp, ParameterType::INTEGER)));
        }

        // Depth can't be expressed portably in SQL, so when capped we over-fetch and filter
        // in PHP, stopping once enough qualifying rows are queued.
        $rows = $qb->setMaxResults($level > 0 ? max($limit * 20, 5000) : $limit)
            ->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($stats['new'] >= $limit) {
                break;
            }
            if ($level > 0 && $this->folderDepth((string)$row['identifier'], $folderPrefix) > $level) {
                continue;
            }
            if (isset($alreadyQuarantined[$row['identifier']])) {
                continue;
            }

            $absolutePath = rtrim($storageBasePath, '/') . '/' . ltrim((string)$row['identifier'], '/');

            // sys_file.missing is a stale FAL flag, not a live disk check — files that are
            // actually gone belong to the "Missing" scan type, not "Unused".
            if (!is_file($absolutePath)) {
                continue;
            }

            $mtime = (int)filemtime($absolutePath);
            $sha1  = $this->computeSha1($absolutePath);

            $this->quarantineRepository->upsertCandidate([
                'storage_uid'            => $storageUid,
                'file_uid'               => (int)$row['uid'],
                'identifier'             => $row['identifier'],
                'name'                   => $row['name'],
                'mime_type'              => $row['mime_type'],
                'size'                   => (int)$row['size'],
                'sha1'                   => $sha1,
                'mtime'                  => $mtime,
                'absolute_path_snapshot' => $absolutePath,
                'reason'                 => QuarantineRepository::REASON_UNUSED,
                'scan_id'                => $scanId,
            ]);

            $stats['new']++;
        }

        return $stats;
    }

    /**
     * Queue FAL records whose physical file is actually gone from disk.
     *
     * sys_file.missing is only set by TYPO3's own indexer runs (backend browsing, the
     * "Related content" file-existence check, etc.) — it's rarely kept in sync in practice,
     * so a scan that only trusted `missing = 1` would silently find nothing even when the
     * "Missing" flag is checked. Instead this does a live is_file() check against every
     * sys_file row in scope, and self-heals rows where the stored flag is stale (file is
     * actually back / was never really gone) by correcting sys_file.missing and dropping
     * any leftover candidate for it.
     */
    public function scanMissingFiles(
        int $storageUid,
        string $scanId,
        string $folderPrefix = '',
        int $level = 0
    ): array {
        $stats             = ['new' => 0, 'updated' => 0];
        $storageBasePath   = $this->fileCleanupService->getStorageBasePath($storageUid);
        $alreadyQuarantined = $this->quarantineRepository->getIdentifiersByStatus(
            $storageUid,
            [QuarantineRepository::STATUS_QUARANTINED]
        );

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $qb->select('uid', 'identifier', 'name', 'size', 'mime_type', 'sha1', 'tstamp', 'missing')
            ->from('sys_file')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)));

        if ($folderPrefix !== '') {
            $normalized = '/' . trim($folderPrefix, '/') . '/';
            $conn       = $this->connectionPool->getConnectionForTable('sys_file');
            $escaped    = $conn->escapeLikeWildcards($normalized);
            $qb->andWhere($qb->expr()->like('identifier', $qb->createNamedParameter($escaped . '%')));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($level > 0 && $this->folderDepth((string)$row['identifier'], $folderPrefix) > $level) {
                continue;
            }

            // A file already moved into quarantine also has sys_file.missing=1 at its
            // original identifier — don't re-queue it as a fresh "missing" candidate.
            if (isset($alreadyQuarantined[$row['identifier']])) {
                continue;
            }

            $absolutePath = rtrim($storageBasePath, '/') . '/' . ltrim((string)$row['identifier'], '/');
            $wasFlaggedMissing = (int)$row['missing'] === 1;

            if (is_file($absolutePath)) {
                if ($wasFlaggedMissing) {
                    $this->connectionPool->getConnectionForTable('sys_file')
                        ->update('sys_file', ['missing' => 0], ['uid' => (int)$row['uid']]);
                    $this->quarantineRepository->deleteCandidateByIdentity(
                        $storageUid,
                        (string)$row['identifier'],
                        QuarantineRepository::REASON_MISSING
                    );
                }
                continue;
            }

            // Genuinely gone from disk — keep the FAL flag in sync while we're here.
            if (!$wasFlaggedMissing) {
                $this->connectionPool->getConnectionForTable('sys_file')
                    ->update('sys_file', ['missing' => 1], ['uid' => (int)$row['uid']]);
            }

            $this->quarantineRepository->upsertCandidate([
                'storage_uid'            => $storageUid,
                'file_uid'               => (int)$row['uid'],
                'identifier'             => $row['identifier'],
                'name'                   => $row['name'],
                'mime_type'              => $row['mime_type'],
                'size'                   => (int)$row['size'],
                'sha1'                   => (string)$row['sha1'],
                'mtime'                  => 0,
                'absolute_path_snapshot' => $absolutePath,
                'reason'                 => QuarantineRepository::REASON_MISSING,
                'scan_id'                => $scanId,
            ]);

            $stats['new']++;
        }

        return $stats;
    }

    /**
     * Queue physical files on disk that have no sys_file record.
     * Skips the quarantine folder itself.
     */
    public function scanPhysicalOrphans(
        int    $storageUid,
        string $folderPrefix,
        int    $limit,
        string $scanId,
        int    $level = 0 // 0 = all levels; otherwise cap recursion depth below $folderPrefix
    ): array {
        $stats           = ['new' => 0, 'updated' => 0];
        $storageBasePath = $this->fileCleanupService->getStorageBasePath($storageUid);

        // Preload all known identifiers for this storage
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $indexed = $qb->select('identifier')->from('sys_file')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        $knownIdentifiers = array_flip(array_column($indexed, 'identifier'));

        $scanPath = $storageBasePath;
        if ($folderPrefix !== '') {
            $scanPath = rtrim($storageBasePath, '/') . '/' . ltrim($folderPrefix, '/');
        }

        if (!is_dir($scanPath)) {
            return $stats;
        }

        $realBase = realpath($storageBasePath);
        $count    = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scanPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        if ($level > 0) {
            // Level 1 = files directly in $scanPath = iterator depth 0.
            $iterator->setMaxDepth($level - 1);
        }

        foreach ($iterator as $fileInfo) {
            if ($count >= $limit) {
                break;
            }
            if (!$fileInfo->isFile()) {
                continue;
            }

            // Skip the quarantine folder
            $realFilePath = $fileInfo->getRealPath();
            if ($realFilePath === false) {
                continue;
            }
            if (str_contains($realFilePath, DIRECTORY_SEPARATOR . self::QUARANTINE_DIR . DIRECTORY_SEPARATOR)) {
                continue;
            }

            // Build FAL identifier relative to storage base
            $identifier = '/' . ltrim(str_replace(rtrim((string)$realBase, '/'), '', $realFilePath), '/');

            if (isset($knownIdentifiers[$identifier])) {
                continue;
            }

            $absolutePath = $realFilePath;
            $sha1         = $this->computeSha1($absolutePath);

            $this->quarantineRepository->upsertCandidate([
                'storage_uid'            => $storageUid,
                'file_uid'               => 0,
                'identifier'             => $identifier,
                'name'                   => $fileInfo->getFilename(),
                'mime_type'              => '',
                'size'                   => (int)$fileInfo->getSize(),
                'sha1'                   => $sha1,
                'mtime'                  => (int)$fileInfo->getMTime(),
                'absolute_path_snapshot' => $absolutePath,
                'reason'                 => QuarantineRepository::REASON_ORPHAN,
                'scan_id'                => $scanId,
            ]);

            $stats['new']++;
            $count++;
        }

        return $stats;
    }

    /**
     * How many folder segments a file sits below $folderPrefix.
     * A file directly inside the folder is depth 1; one subfolder down is depth 2, etc.
     */
    private function folderDepth(string $identifier, string $folderPrefix): int
    {
        $relative = str_starts_with($identifier, $folderPrefix)
            ? substr($identifier, strlen($folderPrefix))
            : $identifier;
        return substr_count(trim($relative, '/'), '/') + 1;
    }

    private function computeSha1(string $absolutePath): string
    {
        if (!is_file($absolutePath)) {
            return '';
        }
        if (filesize($absolutePath) > self::SHA1_SIZE_LIMIT) {
            return '';
        }
        $result = sha1_file($absolutePath);
        return $result !== false ? $result : '';
    }
}
