<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ParameterType;
use Anubit\Filefix\Repository\QuarantineRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

class QuarantineRestoreService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly QuarantineRepository $quarantineRepository,
    ) {}

    /**
     * Move a quarantined file back to its original location and re-enable the FAL record.
     * If the original path is occupied, the file is restored with a timestamp suffix.
     */
    public function restore(array $record, string $storageBasePath): array
    {
        $now                  = time();
        $quarantineIdentifier = $record['quarantine_identifier'];

        if ($quarantineIdentifier === '') {
            return ['success' => false, 'error' => 'no quarantine_identifier stored, cannot restore'];
        }

        $quarantineAbsPath  = rtrim($storageBasePath, '/') . '/' . $quarantineIdentifier;
        $realQuarantinePath = realpath($quarantineAbsPath);

        if ($realQuarantinePath === false || !is_file($realQuarantinePath)) {
            return ['success' => false, 'error' => "quarantine file not found: {$quarantineAbsPath}"];
        }

        $realBase = realpath($storageBasePath);
        if ($realBase !== false && !str_starts_with($realQuarantinePath, $realBase . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'quarantine path security check failed'];
        }

        $originalAbsPath = rtrim($storageBasePath, '/') . '/' . ltrim($record['identifier'], '/');
        $originalDir     = dirname($originalAbsPath);

        if (!is_dir($originalDir) && !mkdir($originalDir, 0755, true) && !is_dir($originalDir)) {
            return ['success' => false, 'error' => "failed to create original directory: {$originalDir}"];
        }

        // If original path occupied, restore with suffix
        $restorePath = $originalAbsPath;
        if (file_exists($originalAbsPath)) {
            $info        = pathinfo($originalAbsPath);
            $ext         = isset($info['extension']) ? ('.' . $info['extension']) : '';
            $restorePath = ($info['dirname'] ?? dirname($originalAbsPath))
                         . '/' . ($info['filename'] ?? basename($originalAbsPath))
                         . '.restored.' . $now . $ext;
        }

        if (!rename($realQuarantinePath, $restorePath)) {
            return ['success' => false, 'error' => "failed to move file back from quarantine"];
        }

        // Re-enable FAL record
        $fileUid = (int)$record['file_uid'];
        if ($fileUid > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
            $qb->getRestrictions()->removeAll();
            $exists = $qb->count('uid')->from('sys_file')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($fileUid, ParameterType::INTEGER)))
                ->executeQuery()->fetchOne() > 0;

            if ($exists) {
                $updateData = ['missing' => 0];
                // If restored to a different path, update identifier
                if ($restorePath !== $originalAbsPath) {
                    $updateData['identifier'] = '/' . ltrim(
                        str_replace(rtrim((string)realpath($storageBasePath), '/'), '', realpath($restorePath) ?: $restorePath),
                        '/'
                    );
                }
                $this->connectionPool->getConnectionForTable('sys_file')
                    ->update('sys_file', $updateData, ['uid' => $fileUid]);
            }
        }

        $this->quarantineRepository->updateRecord((int)$record['uid'], [
            'status'          => QuarantineRepository::STATUS_RESTORED,
            'restored_at'     => $now,
            'updated_at'      => $now,
            'last_checked_at' => $now,
            'error_message'   => null,
        ]);

        return ['success' => true, 'error' => '', 'restored_to' => $restorePath];
    }
}
