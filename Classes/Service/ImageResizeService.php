<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use Anubit\Filefix\Utility\Labels;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resizes one image in place: same file name, same sys_file uid, so references, crops (stored
 * relative) and links stay intact. The format is kept. Used for a dry run (result measured and
 * discarded) and for the real resize.
 *
 * Checks run on the real file, not on sys_file_metadata. The result is only kept when it is
 * at least MIN_SAVING smaller. Replacing goes through FAL (replaceFile): size, sha1 and
 * dimensions are updated, processed files are regenerated because the original sha1 changed.
 */
class ImageResizeService
{
    /** A resized image is only kept when it is at least this much smaller (a resized PNG can even grow) */
    private const MIN_SAVING = 10240;

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly FilefixSettings $settings,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{found: bool, status: string, message: string, identifier?: string, width?: int, height?: int,
     *               size?: int, newWidth?: int, newHeight?: int, newSize?: int, saving?: int, applied?: bool}
     */
    public function resize(int $fileUid, bool $dryRun): array
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Exception) {
            return ['found' => false, 'status' => 'error', 'message' => Labels::get('common.error.fileNotFound')];
        }

        $result = ['found' => true, 'identifier' => $file->getIdentifier(), 'applied' => false];
        $check = $this->checkEligibility($file);
        if ($check['status'] !== 'ok') {
            return $result + $check;
        }
        [$path, $width, $height, $extension] = [$check['path'], $check['width'], $check['height'], $check['extension']];
        $size = (int)filesize($path);
        $result += ['width' => $width, 'height' => $height, 'size' => $size];

        $maxWidth = $this->settings->getImageMaxWidth();
        $maxHeight = $this->settings->getImageMaxHeight();
        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);
        // Own parameters replace the default sharpening, so it is added back. JPEG quality comes from
        // core ($GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']). ###SkipStripProfile### always keeps
        // the colour profile: the original is overwritten, a stripped profile could not be restored.
        $parameters = trim(($graphicalFunctions->cmds[$extension] ?? '') . ' ###SkipStripProfile###');
        $converted = $graphicalFunctions->imageMagickConvert($path, $extension, $maxWidth . 'm', $maxHeight . 'm', $parameters, '', [], true);
        $tempFile = $converted[3] ?? '';
        if ($tempFile === '' || !is_file($tempFile)) {
            return $result + ['status' => 'error', 'message' => Labels::get('resize.error.processing')];
        }

        $newSize = (int)filesize($tempFile);
        $result += [
            'newWidth'  => (int)$converted[0],
            'newHeight' => (int)$converted[1],
            'newSize'   => $newSize,
            'saving'    => $size - $newSize,
        ];

        if ($size - $newSize < self::MIN_SAVING) {
            @unlink($tempFile);
            return $result + ['status' => 'not_smaller', 'message' => Labels::get('resize.notSmaller')];
        }
        if ($dryRun) {
            @unlink($tempFile);
            return $result + ['status' => 'ok', 'message' => Labels::get('resize.dryRun')];
        }

        try {
            $file->getStorage()->replaceFile($file, $tempFile);
        } catch (\Throwable $e) {
            @unlink($tempFile);
            return $result + ['status' => 'error', 'message' => Labels::get('resize.error.replace', $e->getMessage())];
        }
        @unlink($tempFile);

        // Set explicitly: "+" keeps the existing 'applied' => false
        $result['applied'] = true;
        return $result + ['status' => 'ok', 'message' => Labels::get('resize.done')];
    }

    private function countRecordsOfSameFile(File $file): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $qb->getRestrictions()->removeAll();
        return (int)$qb->count('uid')
            ->from('sys_file')
            ->where(
                $qb->expr()->eq('storage', $qb->createNamedParameter($file->getStorage()->getUid(), \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('identifier_hash', $qb->createNamedParameter((string)$file->getProperty('identifier_hash')))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{status: string, message: string, path?: string, width?: int, height?: int, extension?: string}
     */
    private function checkEligibility(File $file): array
    {
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $this->settings->getImageResizeTypes(), true)) {
            return ['status' => 'not_supported', 'message' => Labels::get('resize.reportOnlyType', $extension, implode(', ', $this->settings->getImageResizeTypes()))];
        }
        if ($this->countRecordsOfSameFile($file) > 1) {
            return ['status' => 'not_supported', 'message' => Labels::get('resize.indexedTwice')];
        }
        if ($file->getStorage()->getDriverType() !== 'Local') {
            return ['status' => 'not_supported', 'message' => Labels::get('resize.localOnly')];
        }
        // Local driver: real path of the file, no copy
        $path = $file->getForLocalProcessing(false);
        if (!is_file($path)) {
            return ['status' => 'missing', 'message' => Labels::get('resize.missing')];
        }
        if (!is_writable($path)) {
            return ['status' => 'not_writable', 'message' => Labels::get('resize.notWritable')];
        }
        $info = @getimagesize($path);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return ['status' => 'error', 'message' => Labels::get('resize.noDimensions')];
        }
        if ($info[0] <= $this->settings->getImageMaxWidth() && $info[1] <= $this->settings->getImageMaxHeight()) {
            return ['status' => 'within_limits', 'message' => Labels::get('resize.withinLimits', (int)$info[0], (int)$info[1])];
        }
        return ['status' => 'ok', 'message' => '', 'path' => $path, 'width' => (int)$info[0], 'height' => (int)$info[1], 'extension' => $extension];
    }
}
