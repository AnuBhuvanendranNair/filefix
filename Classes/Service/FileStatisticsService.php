<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Snapshot of unused-file statistics for the dashboard widgets.
 *
 * The unused-files query is too expensive to run on every dashboard load, so the result
 * is stored in sys_registry. It is rebuilt when older than SNAPSHOT_LIFETIME or when the
 * filefix:stats:update command runs (schedulable).
 */
class FileStatisticsService
{
    private const REGISTRY_NAMESPACE = 'tx_filefix';
    private const SNAPSHOT_LIFETIME  = 3600;

    public function __construct(
        private readonly FileCleanupService $fileCleanupService,
        private readonly StorageRepository $storageRepository,
        private readonly Registry $registry,
    ) {}

    /**
     * @return array{generatedAt: int, storageUid: int, unusedCount: int, unusedBytes: int, byExtension: array<string, array{count: int, bytes: int}>}
     */
    public function getSnapshot(?int $storageUid = null): array
    {
        $storageUid ??= $this->getDefaultStorageUid();
        $snapshot = $this->registry->get(self::REGISTRY_NAMESPACE, $this->getRegistryKey($storageUid));
        if (!is_array($snapshot) || (int)($snapshot['generatedAt'] ?? 0) < time() - self::SNAPSHOT_LIFETIME) {
            $snapshot = $this->refresh($storageUid);
        }
        return $snapshot;
    }

    /**
     * @return array{generatedAt: int, storageUid: int, unusedCount: int, unusedBytes: int, byExtension: array<string, array{count: int, bytes: int}>}
     */
    public function refresh(?int $storageUid = null): array
    {
        $storageUid ??= $this->getDefaultStorageUid();
        $physical = $this->fileCleanupService->getUnusedPhysicalStats($storageUid);
        $byExtension = $physical['byExtension'];
        $snapshot = [
            'generatedAt' => time(),
            'storageUid'  => $storageUid,
            'unusedCount' => array_sum(array_column($byExtension, 'count')),
            'unusedBytes' => array_sum(array_column($byExtension, 'bytes')),
            'byExtension' => $byExtension,
            // Unused records left out: file not on disk / file still used through another record
            'notOnDisk'          => $physical['notOnDisk'],
            'usedViaOtherRecord' => $physical['usedViaOtherRecord'],
        ];
        $this->registry->set(self::REGISTRY_NAMESPACE, $this->getRegistryKey($storageUid), $snapshot);
        return $snapshot;
    }

    /**
     * "6.4 GB" from 1 GB on, "850.3 MB" below.
     */
    public static function formatBytes(int $bytes, string $decimalSeparator = '.'): string
    {
        $gigabytes = $bytes / 1073741824;
        if ($gigabytes >= 1) {
            return number_format($gigabytes, 1, $decimalSeparator, '') . ' GB';
        }
        return number_format($bytes / 1048576, 1, $decimalSeparator, '') . ' MB';
    }

    private function getDefaultStorageUid(): int
    {
        return $this->storageRepository->getDefaultStorage()?->getUid() ?? 1;
    }

    private function getRegistryKey(int $storageUid): string
    {
        return 'stats.storage.' . $storageUid;
    }
}
