<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Anubit\Filefix\Repository\QuarantineRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

class QuarantineFlushService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly QuarantineRepository $quarantineRepository,
    ) {}

    /**
     * Move a candidate file to the quarantine folder.
     * Updates status → quarantined on success.
     */
    public function moveToQuarantine(array $record, string $storageBasePath): array
    {
        $now          = time();
        $absolutePath = rtrim($storageBasePath, '/') . '/' . ltrim($record['identifier'], '/');
        $realFile     = realpath($absolutePath);

        if (!$realFile || !is_file($realFile)) {
            return ['success' => false, 'error' => "physical file not found: {$absolutePath}"];
        }

        $realBase = realpath($storageBasePath);
        if ($realBase !== false && !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'path security check failed: file outside storage base'];
        }

        $dateDir      = date('Y/m/d', $now);
        $fileUid      = (int)$record['file_uid'];
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string)$record['identifier']));
        $prefix       = $fileUid > 0 ? "{$fileUid}-" : 'orphan-';

        $quarantineRelPath = '_filefix_quarantine/' . $dateDir . '/' . $prefix . $safeFilename;
        $quarantineAbsPath = rtrim($storageBasePath, '/') . '/' . $quarantineRelPath;

        $quarantineDir = dirname($quarantineAbsPath);
        if (!is_dir($quarantineDir) && !mkdir($quarantineDir, 0755, true) && !is_dir($quarantineDir)) {
            return ['success' => false, 'error' => "failed to create quarantine directory: {$quarantineDir}"];
        }

        // Avoid overwriting existing quarantine file
        if (file_exists($quarantineAbsPath)) {
            $quarantineAbsPath .= '.' . $now;
            $quarantineRelPath .= '.' . $now;
        }

        if (!rename($realFile, $quarantineAbsPath)) {
            return ['success' => false, 'error' => "rename failed: {$realFile} → {$quarantineAbsPath}"];
        }

        // Mark original sys_file as missing (file no longer at indexed location)
        if ($fileUid > 0) {
            $this->connectionPool->getConnectionForTable('sys_file')
                ->update('sys_file', ['missing' => 1], ['uid' => $fileUid]);
        }

        $this->quarantineRepository->updateRecord((int)$record['uid'], [
            'status'                 => QuarantineRepository::STATUS_QUARANTINED,
            'quarantine_identifier'  => $quarantineRelPath,
            'quarantined_at'         => $now,
            'updated_at'             => $now,
            'last_checked_at'        => $now,
            'metadata_json'          => json_encode([
                'original_path'    => $absolutePath,
                'quarantine_path'  => $quarantineAbsPath,
                'moved_at'         => date('c', $now),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message'          => null,
        ]);

        return ['success' => true, 'error' => '', 'quarantine_path' => $quarantineRelPath];
    }

    /**
     * Permanently delete a quarantined file and remove its FAL records.
     * Updates status → flushed on success.
     */
    public function flushQuarantined(array $record, string $storageBasePath): array
    {
        $now                  = time();
        $quarantineIdentifier = $record['quarantine_identifier'];

        if ($quarantineIdentifier === '') {
            return ['success' => false, 'error' => 'no quarantine_identifier stored, cannot flush'];
        }

        $quarantineAbsPath = rtrim($storageBasePath, '/') . '/' . $quarantineIdentifier;
        $realBase          = realpath($storageBasePath);
        $realPath          = realpath($quarantineAbsPath);

        // Security: quarantine path must be inside storage base
        if ($realPath !== false && $realBase !== false && !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'quarantine path security check failed'];
        }

        // Delete physical file if it still exists
        if ($realPath !== false && is_file($realPath) && !unlink($realPath)) {
            return ['success' => false, 'error' => "failed to delete quarantine file: {$quarantineAbsPath}"];
        }

        // Remove FAL records
        $fileUid = (int)$record['file_uid'];
        if ($fileUid > 0) {
            $this->connectionPool->getConnectionForTable('sys_file_reference')
                ->delete('sys_file_reference', ['uid_local' => $fileUid]);
            $this->connectionPool->getConnectionForTable('sys_file')
                ->delete('sys_file', ['uid' => $fileUid]);
        }

        $this->quarantineRepository->updateRecord((int)$record['uid'], [
            'status'         => QuarantineRepository::STATUS_FLUSHED,
            'flushed_at'     => $now,
            'updated_at'     => $now,
            'last_checked_at'=> $now,
            'error_message'  => null,
        ]);

        return ['success' => true, 'error' => ''];
    }
}
