<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Anubit\Filefix\Utility\Labels;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class MimeTypeService
{
    private const MIME_EXTENSION_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'tiff' => 'image/tiff',
        'tif'  => 'image/tiff',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'yaml' => 'text/yaml',
        'yml'  => 'text/yaml',
        'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
    ];

    // Extensions where finfo cannot detect the specific MIME (returns wrong/generic type) — fix is DB-only, no IM conversion.
    private const DB_ONLY_EXTENSIONS = ['css', 'js', 'yaml', 'yml', 'dotx'];

    private const MIME_TO_IM_PREFIX = [
        'image/jpeg'                  => 'JPEG',
        'image/png'                   => 'PNG',
        'image/gif'                   => 'GIF',
        'image/webp'                  => 'WEBP',
        'image/bmp'                   => 'BMP',
        'image/tiff'                  => 'TIFF',
        'image/vnd.adobe.photoshop'   => 'PSD',
        'application/photoshop'       => 'PSD',
        'application/x-photoshop'     => 'PSD',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getFileadminPath(): string
    {
        return Environment::getPublicPath() . '/fileadmin';
    }

    /**
     * Scans $path for image files and cross-references against sys_file.
     * Returns entries where:
     *   - the physical content doesn't match the extension (content mismatch), OR
     *   - the sys_file.mime_type doesn't match the extension (DB out of sync).
     *
     * Each entry contains:
     *   path, relativePath, extension, actualMime, expectedMime,
     *   dbMime (null if not indexed), dbUid,
     *   contentMismatch (bool), fixable (bool), dbOutOfSync (bool), selectable (bool)
     */
    public function scanDirectory(string $path, bool $recursive = true, int $storageUid = 0, string $storageBasePath = ''): array
    {
        $mismatches    = [];
        $finfo         = new \finfo(FILEINFO_MIME_TYPE);
        $fileadminPath = $this->getFileadminPath();

        $storageBasePath = rtrim($storageBasePath !== '' ? $storageBasePath : $fileadminPath, '/');
        $folderIdentifier = '/' . ltrim(str_replace($storageBasePath, '', rtrim($path, '/')), '/') . '/';

        $dbByIdentifier = $storageUid > 0
            ? $this->loadDbRecords($storageUid, $folderIdentifier, $recursive)
            : [];

        try {
            $iterator = $recursive
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
                : new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $extension = strtolower($file->getExtension());
                if (!isset(self::MIME_EXTENSION_MAP[$extension])) {
                    continue;
                }

                $actualMime   = $finfo->file($file->getPathname());
                $expectedMime = self::MIME_EXTENSION_MAP[$extension];
                $identifier   = '/' . ltrim(str_replace($storageBasePath, '', $file->getPathname()), '/');

                $dbRecord = $dbByIdentifier[$identifier] ?? null;
                $dbMime   = $dbRecord !== null ? $dbRecord['mime_type'] : null;
                $dbUid    = $dbRecord !== null ? (int)$dbRecord['uid'] : 0;

                $contentMismatch = $actualMime !== $expectedMime;
                $dbOutOfSync     = $dbRecord !== null && $dbMime !== $expectedMime;

                if ($this->isDbOnlyExtension($extension)) {
                    // finfo always returns a generic type for these extensions — content mismatch is
                    // expected and unfixable. Only report when the DB record has the wrong mime type.
                    if (!$dbOutOfSync) {
                        continue;
                    }
                    $mismatches[] = [
                        'path'            => $file->getPathname(),
                        'relativePath'    => ltrim(str_replace($fileadminPath, '', $file->getPathname()), '/'),
                        'extension'       => $extension,
                        'actualMime'      => $actualMime,
                        'expectedMime'    => $expectedMime,
                        'dbMime'          => $dbMime,
                        'dbUid'           => $dbUid,
                        'contentMismatch' => false,
                        'fixable'         => false,
                        'dbOutOfSync'     => true,
                        'selectable'      => true,
                    ];
                    continue;
                }

                if (!$contentMismatch && !$dbOutOfSync) {
                    continue;
                }

                $fixable    = $contentMismatch && $this->isFixable($actualMime, $extension);
                $selectable = $fixable || (!$contentMismatch && $dbOutOfSync);

                $mismatches[] = [
                    'path'            => $file->getPathname(),
                    'relativePath'    => ltrim(str_replace($fileadminPath, '', $file->getPathname()), '/'),
                    'extension'       => $extension,
                    'actualMime'      => $actualMime,
                    'expectedMime'    => $expectedMime,
                    'dbMime'          => $dbMime,
                    'dbUid'           => $dbUid,
                    'contentMismatch' => $contentMismatch,
                    'fixable'         => $fixable,
                    'dbOutOfSync'     => $dbOutOfSync,
                    'selectable'      => $selectable,
                ];
            }
        } catch (\UnexpectedValueException $e) {
            // unreadable directory — return what we have
        }

        return $mismatches;
    }

    public function needsConversion(string $filePath): bool
    {
        $extension    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_EXTENSION_MAP[$extension] ?? null;
        if ($expectedMime === null) {
            return false;
        }
        return (new \finfo(FILEINFO_MIME_TYPE))->file($filePath) !== $expectedMime;
    }

    public function isDbOnlyExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::DB_ONLY_EXTENSIONS, true);
    }

    private function isFixable(string $actualMime, string $extension): bool
    {
        if ($this->isDbOnlyExtension($extension)) {
            return isset(self::MIME_EXTENSION_MAP[$extension]);
        }
        return isset(self::MIME_TO_IM_PREFIX[$actualMime])
            && isset(self::MIME_EXTENSION_MAP[$extension]);
    }

    public function fixDbMimeType(string $filePath, int $storageUid, string $storageBasePath = ''): bool
    {
        $extension    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_EXTENSION_MAP[$extension] ?? null;
        if ($expectedMime === null || !$this->isDbOnlyExtension($extension)) {
            return false;
        }

        $storageBasePath = rtrim($storageBasePath !== '' ? $storageBasePath : $this->getFileadminPath(), '/');
        $identifier      = '/' . ltrim(str_replace($storageBasePath, '', $filePath), '/');

        $qb      = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $affected = $qb
            ->update('sys_file')
            ->set('mime_type', $expectedMime)
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('identifier', $qb->createNamedParameter($identifier))
            )
            ->executeStatement();

        return $affected > 0;
    }

    /**
     * Returns sys_file records keyed by identifier for the given storage + folder prefix.
     *
     * @return array<string, array{uid: int, identifier: string, mime_type: string}>
     */
    private function loadDbRecords(int $storageUid, string $folderIdentifier, bool $recursive): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();

        $rows = $qb
            ->select('uid', 'identifier', 'mime_type')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($storageUid, Connection::PARAM_INT)),
                $qb->expr()->like('identifier', $qb->createNamedParameter($folderIdentifier . '%'))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if (!$recursive) {
            $prefixLen = strlen($folderIdentifier);
            $rows = array_filter($rows, static function (array $row) use ($folderIdentifier, $prefixLen): bool {
                $tail = substr($row['identifier'], $prefixLen);
                return $tail !== '' && !str_contains($tail, '/');
            });
        }

        return array_column($rows, null, 'identifier');
    }

    /**
     * Converts the file content to match its extension using ImageMagick or GraphicsMagick.
     * Writes to a temp file first to avoid data loss on failure.
     */
    public function fixFile(string $filePath): bool
    {
        [$success] = $this->fixFileWithError($filePath);
        return $success;
    }

    /**
     * @return array{0: bool, 1: string}  [$success, $errorDetail]
     */
    public function fixFileWithError(string $filePath): array
    {
        $extension    = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $expectedMime = self::MIME_EXTENSION_MAP[$extension] ?? null;
        if ($expectedMime === null) {
            return [false, Labels::get('mime.error.unsupported')];
        }

        // Text-only extensions need no content conversion — caller handles DB update.
        if ($this->isDbOnlyExtension($extension)) {
            return [true, ''];
        }

        if (!isset(self::MIME_TO_IM_PREFIX[$expectedMime])) {
            return [false, Labels::get('mime.error.unsupported')];
        }

        $gfx      = $GLOBALS['TYPO3_CONF_VARS']['GFX'] ?? [];
        $imPath   = rtrim($gfx['processor_path'] ?? '/usr/bin/', '/');
        $isGM     = ($gfx['processor'] ?? 'ImageMagick') === 'GraphicsMagick';
        $imPrefix = self::MIME_TO_IM_PREFIX[$expectedMime];
        $tempFile = $filePath . '.mimefix_tmp';

        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $isPsd      = in_array($actualMime, ['image/vnd.adobe.photoshop', 'application/photoshop', 'application/x-photoshop'], true);

        // GM has no PSD decode delegate — use PHP Imagick extension for PSD files.
        if ($isPsd && extension_loaded('imagick')) {
            try {
                $imagick = new \Imagick($filePath . '[0]');
                $imagick->setImageFormat(strtolower($imPrefix));
                $imagick->writeImage($tempFile);
                $imagick->destroy();
                if (file_exists($tempFile)) {
                    rename($tempFile, $filePath);
                    return [true, ''];
                }
            } catch (\ImagickException $e) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
                return [false, 'Imagick: ' . $e->getMessage()];
            }
        }

        // PSD files have multiple layers — append [0] to read only the composite/merged layer,
        // otherwise ImageMagick writes one file per layer (file-0, file-1, ...) and the expected path never exists.
        $inputArg = $isPsd ? escapeshellarg($filePath . '[0]') : escapeshellarg($filePath);

        // For PSD files when GM is configured, try ImageMagick convert directly as GM lacks PSD support.
        $convertBin = ($isGM && $isPsd) ? ($imPath . '/convert') : ($isGM ? ($imPath . '/gm') : ($imPath . '/convert'));
        $cmd = ($isGM && !$isPsd)
            ? escapeshellcmd($convertBin) . ' convert ' . $inputArg . ' ' . escapeshellarg($imPrefix . ':' . $tempFile)
            : escapeshellcmd($convertBin) . ' ' . $inputArg . ' ' . escapeshellarg($imPrefix . ':' . $tempFile);

        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode === 0 && file_exists($tempFile)) {
            rename($tempFile, $filePath);
            return [true, ''];
        }

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        $detail = implode(' | ', array_filter($output));
        return [false, $detail ?: Labels::get('mime.error.exitCode', $returnCode)];
    }
}
