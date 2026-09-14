<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ParameterType;
use Anubit\Filefix\Repository\QuarantineRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

class QuarantineValidationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Re-verify a queued record is safe to process.
     *
     * Returns ['valid' => bool, 'error' => string, 'skip' => bool]
     * skip=true  → mark as skipped (file changed, no longer a candidate)
     * skip=false → mark as failed  (security or unexpected error)
     */
    public function validate(array $record, string $storageBasePath, bool $skipHashCheck = false): array
    {
        $fileUid  = (int)$record['file_uid'];
        $reason   = $record['reason'];

        if ($reason !== QuarantineRepository::REASON_ORPHAN) {
            $sysFile = $this->getSysFile($fileUid);

            if ($sysFile === null) {
                return $this->skip("sys_file uid={$fileUid} no longer exists");
            }
            if ((int)$sysFile['storage'] !== (int)$record['storage_uid']) {
                return $this->skip('storage_uid mismatch: file may have moved to another storage');
            }
            if ($sysFile['identifier'] !== $record['identifier']) {
                return $this->skip('identifier mismatch: file may have been renamed or moved');
            }

            if ($reason === QuarantineRepository::REASON_UNUSED && $this->hasActiveReference($fileUid)) {
                return $this->skip('file now has active FAL references, no longer safe to remove');
            }
        }

        $absolutePath = rtrim($storageBasePath, '/') . '/' . ltrim($record['identifier'], '/');

        // For missing_physical_file: absence is expected, nothing more to check
        if ($reason === QuarantineRepository::REASON_MISSING) {
            $realFile = realpath($absolutePath);
            if ($realFile && is_file($realFile)) {
                return $this->skip('physical file now exists (was missing at scan time), re-evaluate');
            }
            return ['valid' => true, 'error' => '', 'skip' => false];
        }

        // Physical file must exist for unused_fal_file and physical_orphan
        $realFile = realpath($absolutePath);
        if (!$realFile || !is_file($realFile)) {
            return $this->skip("physical file no longer exists: {$absolutePath}");
        }

        // Path traversal safety: resolved path must be inside storage base
        $realBase = realpath($storageBasePath);
        if ($realBase !== false && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return $this->fail('path security check failed: resolved path is outside storage base');
        }

        // Size consistency
        $currentSize = (int)filesize($realFile);
        if ((int)$record['size'] > 0 && $currentSize !== (int)$record['size']) {
            return $this->skip("file size changed since scan (expected {$record['size']}, got {$currentSize})");
        }

        // mtime consistency
        $currentMtime = (int)filemtime($realFile);
        if ((int)$record['mtime'] > 0 && $currentMtime !== (int)$record['mtime']) {
            return $this->skip('file mtime changed since scan — file may have been updated');
        }

        // SHA1 consistency (skip if not computed at scan time)
        if (!$skipHashCheck && $record['sha1'] !== '') {
            $currentSha1 = sha1_file($realFile);
            if ($currentSha1 !== false && $currentSha1 !== $record['sha1']) {
                return $this->skip('file content changed since scan (sha1 mismatch)');
            }
        }

        return ['valid' => true, 'error' => '', 'skip' => false];
    }

    private function getSysFile(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid', 'storage', 'identifier')
            ->from('sys_file')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    private function hasActiveReference(int $fileUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('uid_local', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    private function skip(string $error): array
    {
        return ['valid' => false, 'error' => $error, 'skip' => true];
    }

    private function fail(string $error): array
    {
        return ['valid' => false, 'error' => $error, 'skip' => false];
    }
}
