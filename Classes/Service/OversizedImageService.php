<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Read-only report of images larger than a maximum width/height, with the estimated saving when
 * resized to fit (aspect ratio kept).
 *
 * Only files that exist on disk are reported, sizes are taken from disk, each physical file once:
 * sys_file.size / sys_file.missing are not trusted (stale in copied or broken installations), and
 * several sys_file records can point to the same path. Dimensions come from sys_file_metadata.
 *
 * Estimate: new size = size × (new pixels / old pixels). Real results depend on format and
 * compression; the estimate is shown as such.
 */
class OversizedImageService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FileCleanupService $fileCleanupService,
    ) {}

    /**
     * Summary and one page of oversized images (largest estimated saving first).
     *
     * @return array{summary: array{count: int, size: int, estimatedSize: int, saving: int, notOnDisk: int, unknownDimensions: int}, images: array}
     */
    public function getReport(
        int $storageUid,
        string $folderPrefix,
        bool $recursive,
        int $maxWidth,
        int $maxHeight,
        array $fileTypes,
        int $limit,
        int $offset
    ): array {
        $basePath = $this->fileCleanupService->getStorageBasePath($storageUid);
        $images = [];
        $seenPaths = [];
        $notOnDisk = 0;

        foreach ($this->fetchCandidates($storageUid, $folderPrefix, $recursive, $maxWidth, $maxHeight, $fileTypes) as $row) {
            // Several records for the same path: report the physical file once
            if (isset($seenPaths[$row['identifier_hash']])) {
                continue;
            }
            $seenPaths[$row['identifier_hash']] = true;

            $absolutePath = $basePath . $row['identifier'];
            if (!is_file($absolutePath)) {
                $notOnDisk++;
                continue;
            }
            $size = (int)filesize($absolutePath);
            $width = (int)$row['width'];
            $height = (int)$row['height'];
            $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
            $estimatedSize = (int)round($size * $scale * $scale);
            $images[] = [
                'uid'           => (int)$row['uid'],
                'identifier'    => (string)$row['identifier'],
                'name'          => (string)$row['name'],
                'extension'     => strtolower((string)$row['extension']),
                'size'          => $size,
                'width'         => $width,
                'height'        => $height,
                // round(), not floor(): 6510 × (2560 / 6510) must give 2560, not 2559
                'newWidth'      => max(1, min($maxWidth, (int)round($width * $scale))),
                'newHeight'     => max(1, min($maxHeight, (int)round($height * $scale))),
                'estimatedSize' => $estimatedSize,
                'saving'        => $size - $estimatedSize,
                'savingPercent' => (int)round((1 - $scale * $scale) * 100),
            ];
        }

        usort($images, static fn(array $a, array $b): int => [$b['saving'], $a['uid']] <=> [$a['saving'], $b['uid']]);
        $size = array_sum(array_column($images, 'size'));
        $estimatedSize = array_sum(array_column($images, 'estimatedSize'));

        $page = array_slice($images, $offset, $limit);
        $references = $this->countReferences(array_column($page, 'uid'));
        foreach ($page as &$image) {
            $image['references'] = $references[$image['uid']] ?? 0;
        }
        unset($image);

        return [
            'summary' => [
                'count'             => count($images),
                'size'              => $size,
                'estimatedSize'     => $estimatedSize,
                'saving'            => $size - $estimatedSize,
                'notOnDisk'         => $notOnDisk,
                'unknownDimensions' => $this->countUnknownDimensions($storageUid, $folderPrefix, $recursive, $fileTypes, $basePath),
            ],
            'images' => $page,
        ];
    }

    /**
     * Records whose metadata says the image is too large. One row per record: MAX() over the
     * default-language metadata, some files have several metadata records. Joined directly
     * (index on sys_file_metadata.file); a grouped derived table has no index and is slow.
     */
    private function fetchCandidates(int $storageUid, string $folderPrefix, bool $recursive, int $maxWidth, int $maxHeight, array $fileTypes): iterable
    {
        [$folderSql, $params, $types] = $this->buildFolderCondition($folderPrefix, $recursive);
        // Both limits are validated integers
        $result = $this->getConnection()->executeQuery(
            'SELECT f.uid, f.identifier, f.identifier_hash, f.name, f.extension,'
            . ' MAX(m.width) AS width, MAX(m.height) AS height'
            . ' FROM sys_file f'
            . ' INNER JOIN sys_file_metadata m ON m.file = f.uid AND m.sys_language_uid = 0'
            . ' WHERE f.storage = :storage AND LOWER(f.extension) IN (:types)'
            . $folderSql
            . ' GROUP BY f.uid, f.identifier, f.identifier_hash, f.name, f.extension'
            . ' HAVING MAX(m.width) > 0 AND MAX(m.height) > 0'
            . ' AND (MAX(m.width) > ' . $maxWidth . ' OR MAX(m.height) > ' . $maxHeight . ')'
            . ' ORDER BY f.uid',
            ['storage' => $storageUid, 'types' => $fileTypes] + $params,
            ['storage' => ParameterType::INTEGER, 'types' => ArrayParameterType::STRING] + $types
        );
        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }

    /**
     * @return array<int, int> active file references per sys_file uid
     */
    private function countReferences(array $fileUids): array
    {
        if ($fileUids === []) {
            return [];
        }
        $rows = $this->connectionPool->getConnectionForTable('sys_file_reference')->executeQuery(
            'SELECT uid_local, COUNT(*) AS cnt FROM sys_file_reference WHERE deleted = 0 AND uid_local IN (:uids) GROUP BY uid_local',
            ['uids' => $fileUids],
            ['uids' => ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
        return array_column(array_map(static fn(array $r): array => ['uid' => (int)$r['uid_local'], 'cnt' => (int)$r['cnt']], $rows), 'cnt', 'uid');
    }

    /**
     * Images on disk without known dimensions (not part of the report).
     */
    private function countUnknownDimensions(int $storageUid, string $folderPrefix, bool $recursive, array $fileTypes, string $basePath): int
    {
        [$folderSql, $params, $types] = $this->buildFolderCondition($folderPrefix, $recursive);
        $result = $this->getConnection()->executeQuery(
            'SELECT DISTINCT f.identifier FROM sys_file f'
            . ' WHERE f.storage = :storage AND LOWER(f.extension) IN (:types)'
            . ' AND NOT EXISTS (SELECT 1 FROM sys_file_metadata m WHERE m.file = f.uid AND m.sys_language_uid = 0'
            . ' AND m.width > 0 AND m.height > 0)'
            . $folderSql,
            ['storage' => $storageUid, 'types' => $fileTypes] + $params,
            ['storage' => ParameterType::INTEGER, 'types' => ArrayParameterType::STRING] + $types
        );
        $count = 0;
        while (($identifier = $result->fetchOne()) !== false) {
            $count += is_file($basePath . $identifier) ? 1 : 0;
        }
        return $count;
    }

    /**
     * @return array{0: string, 1: array, 2: array}
     */
    private function buildFolderCondition(string $folderPrefix, bool $recursive): array
    {
        $connection = $this->getConnection();
        $normalized = '/' . trim($folderPrefix, '/');
        $normalized = $normalized === '/' ? '/' : $normalized . '/';
        $escaped = $connection->escapeLikeWildcards($normalized);
        $sql = ' AND f.identifier LIKE :folder';
        $params = ['folder' => $escaped . '%'];
        $types = ['folder' => ParameterType::STRING];
        if (!$recursive) {
            // Direct children only: nothing after a further slash
            $sql .= ' AND f.identifier NOT LIKE :subfolders';
            $params['subfolders'] = $escaped . '%/%';
            $types['subfolders'] = ParameterType::STRING;
        }
        return [$sql, $params, $types];
    }

    private function getConnection(): \TYPO3\CMS\Core\Database\Connection
    {
        return $this->connectionPool->getConnectionForTable('sys_file');
    }
}
