<?php

declare(strict_types=1);

namespace Anubit\Filefix\Controller;

use Anubit\Filefix\Service\ActionLogger;
use Anubit\Filefix\Service\FileCleanupService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

class FileCleanupController
{
    private const VALID_PER_PAGE = [20, 50, 100];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly FlashMessageService $flashMessageService,
        private readonly FileCleanupService $fileCleanupService,
        private readonly ResourceFactory $resourceFactory,
        private readonly ActionLogger $actionLogger,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return $this->handleCleanup($request);
        }

        $queryParams = $request->getQueryParams();
        $folderId    = (string)($queryParams['id'] ?? '');
        $showFilter  = in_array($queryParams['show'] ?? '', ['all', 'present', 'missing'], true)
                       ? (string)$queryParams['show']
                       : 'present';
        $perPage     = (int)($queryParams['perPage'] ?? 20);
        $perPage     = in_array($perPage, self::VALID_PER_PAGE, true) ? $perPage : 20;
        $page        = max(1, (int)($queryParams['page'] ?? 1));
        $extFilter   = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)($queryParams['ext'] ?? '')));
        $extFilter   = substr($extFilter, 0, 20);
        $viewMode    = ($queryParams['viewMode'] ?? '') === 'thumbs' ? 'thumbs' : 'list';

        $folder       = $this->resolveFolder($folderId);
        $folderPrefix = $this->getFolderPrefix($folder);
        $folderName   = $folder ? ($folder->getName() ?: $folder->getStorage()->getName()) : '';

        $storageBasePath   = $this->fileCleanupService->getStorageBasePath();
        $storagePublicBase = $this->fileCleanupService->getStoragePublicBase();

        // One query for extension list, unfiltered total and per-extension total.
        // Unfiltered total is used only to decide whether to show the "all clean" state.
        // Must stay independent of $showFilter/$extFilter so the filter/delete controls
        // don't disappear just because the current filter happens to match zero files.
        $extensionCounts     = $this->fileCleanupService->getUnusedExtensionCounts(1, $folderPrefix);
        $availableExtensions = array_values(array_filter(array_keys($extensionCounts), static fn($ext) => $ext !== ''));
        $totalUnfiltered     = array_sum($extensionCounts);

        if ($showFilter === 'all') {
            // Fast path: paginate entirely in the DB query
            $totalUnused = $extFilter === '' ? $totalUnfiltered : ($extensionCounts[$extFilter] ?? 0);
            $totalPages  = max(1, (int)ceil($totalUnused / $perPage));
            $page        = min($page, $totalPages);
            $offset      = ($page - 1) * $perPage;

            $unusedFiles = $this->fileCleanupService->findUnusedFiles(1, $folderPrefix, $perPage, $offset, $extFilter);
            $unusedFiles = array_map(static function (array $file) use ($storageBasePath): array {
                $abs = rtrim($storageBasePath, '/') . '/' . ltrim($file['identifier'], '/');
                $file['physicallyMissing'] = !file_exists($abs);
                return $file;
            }, $unusedFiles);
        } else {
            // Filtered path: fetch all, apply file_exists check, then paginate in PHP.
            // Cap at 10 000 to guard against extremely large storages.
            $all = $this->fileCleanupService->findUnusedFiles(1, $folderPrefix, 10000, 0, $extFilter);
            $all = array_map(static function (array $file) use ($storageBasePath): array {
                $abs = rtrim($storageBasePath, '/') . '/' . ltrim($file['identifier'], '/');
                $file['physicallyMissing'] = !file_exists($abs);
                return $file;
            }, $all);

            if ($showFilter === 'present') {
                $all = array_values(array_filter($all, static fn($f) => !$f['physicallyMissing']));
            } else {
                $all = array_values(array_filter($all, static fn($f) => $f['physicallyMissing']));
            }

            $totalUnused = count($all);
            $totalPages  = max(1, (int)ceil($totalUnused / $perPage));
            $page        = min($page, $totalPages);
            $offset      = ($page - 1) * $perPage;
            $unusedFiles = array_slice($all, $offset, $perPage);
        }

        // Always resolved: the template renders list and thumbnail view, the toggle is client-side
        $unusedFiles = array_map(
            fn(array $file): array => $file + ['thumbnailUrl' => $this->getThumbnailUrl($file)],
            $unusedFiles
        );

        $baseParams = ['perPage' => $perPage, 'show' => $showFilter, 'ext' => $extFilter, 'viewMode' => $viewMode];
        if ($folderId !== '') {
            $baseParams['id'] = $folderId;
        }

        $folderParams = $folderId !== '' ? ['id' => $folderId] : [];
        $perPageOptions = [];
        foreach (self::VALID_PER_PAGE as $pp) {
            $perPageOptions[] = [
                'value'  => $pp,
                'url'    => (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', array_merge($baseParams, ['perPage' => $pp])),
                'active' => ($pp === $perPage),
            ];
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        if ($folder !== null) {
            $moduleTemplate->getDocHeaderComponent()->setMetaInformationForResource($folder);
        }

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', $baseParams))
                ->setTitle('Refresh')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
            ButtonBar::BUTTON_POSITION_RIGHT
        );

        $moduleTemplate->assignMultiple([
            'unusedFiles'        => $unusedFiles,
            'unusedCount'        => $totalUnused,
            'totalUnfiltered'    => $totalUnfiltered,
            'showFilter'         => $showFilter,
            'extFilter'          => $extFilter,
            'viewMode'           => $viewMode,
            'availableExtensions' => $availableExtensions,
            'storagePublicBase'  => $storagePublicBase,
            'cleanupActionUrl'   => (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup'),
            'downloadZipUrl'     => (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', ['download_zip' => '1']),
            'viewListUrl'        => (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', array_merge($baseParams, ['viewMode' => 'list'])),
            'viewThumbsUrl'      => (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', array_merge($baseParams, ['viewMode' => 'thumbs'])),
            'folderId'           => $folderId,
            'folderName'         => $folderName,
            'perPageOptions'     => $perPageOptions,
            'perPage'            => $perPage,
            'currentPage'        => $page,
            'totalPages'         => $totalPages,
            'rangeFrom'          => $totalUnused > 0 ? $offset + 1 : 0,
            'rangeTo'            => min($offset + $perPage, $totalUnused),
            'prevPageUrl'        => $page > 1
                ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', array_merge($baseParams, ['page' => $page - 1]))
                : null,
            'nextPageUrl'        => $page < $totalPages
                ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', array_merge($baseParams, ['page' => $page + 1]))
                : null,
        ]);

        return $moduleTemplate->renderResponse('FileCleanup/Index');
    }

    private function handleCleanup(ServerRequestInterface $request): ResponseInterface
    {
        if (!empty($request->getQueryParams()['download_zip'])) {
            return $this->handleZipDownload($request);
        }

        $parsedBody = $request->getParsedBody();
        $type       = (string)($parsedBody['cleanup_type'] ?? '');
        $uids       = (array)($parsedBody['file_uids'] ?? []);
        $folderId   = (string)($parsedBody['redirect_folder_id'] ?? '');
        $showFilter = (string)($parsedBody['redirect_show'] ?? 'present');
        $perPage    = (int)($parsedBody['redirect_per_page'] ?? 20);
        $perPage    = in_array($perPage, self::VALID_PER_PAGE, true) ? $perPage : 20;
        $extFilter  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)($parsedBody['redirect_ext'] ?? '')));
        $extFilter  = substr($extFilter, 0, 20);
        $viewMode   = ($parsedBody['redirect_view_mode'] ?? '') === 'thumbs' ? 'thumbs' : 'list';

        $storageBasePath = $this->fileCleanupService->getStorageBasePath();
        $queue           = $this->flashMessageService->getMessageQueueByIdentifier();

        if ($type === 'unused_files') {
            [$deleted, $errors, $deletedFiles] = $this->fileCleanupService->deleteFiles($uids, $storageBasePath);
            foreach ($deletedFiles as $f) {
                $this->actionLogger->log(ActionLogger::ACTION_DELETE_DIRECT, $f['identifier'], $f['name'], 1, $f['uid']);
            }
            if ($deleted > 0) {
                $queue->enqueue(new FlashMessage(
                    $deleted . ' unused file(s) deleted from disk and FAL.',
                    'File Cleanup',
                    ContextualFeedbackSeverity::OK,
                    true
                ));
            }
        } elseif ($type === 'flush_folder') {
            $folder       = $this->resolveFolder($folderId);
            $folderPrefix = $this->getFolderPrefix($folder);
            $allUids      = $this->fileCleanupService->findAllUnusedFileUids(1, $folderPrefix, $extFilter);
            [$deleted, $errors, $deletedFiles] = $this->fileCleanupService->deleteFiles($allUids, $storageBasePath);
            foreach ($deletedFiles as $f) {
                $this->actionLogger->log(ActionLogger::ACTION_DELETE_DIRECT, $f['identifier'], $f['name'], 1, $f['uid'], 'flush_folder');
            }
            if ($deleted > 0) {
                $queue->enqueue(new FlashMessage(
                    $deleted . ' unused file(s) deleted from disk and FAL.',
                    'Folder Flush',
                    ContextualFeedbackSeverity::OK,
                    true
                ));
            }
        } else {
            $errors = [];
        }

        foreach ($errors as $msg) {
            $queue->enqueue(new FlashMessage(
                $msg,
                'Cleanup failed',
                ContextualFeedbackSeverity::ERROR,
                true
            ));
        }

        $redirectParams = ['perPage' => $perPage, 'show' => $showFilter, 'ext' => $extFilter, 'viewMode' => $viewMode];
        if ($folderId !== '') {
            $redirectParams['id'] = $folderId;
        }

        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup', $redirectParams)
        );
    }

    private function handleZipDownload(ServerRequestInterface $request): ResponseInterface
    {
        $uids            = (array)($request->getParsedBody()['file_uids'] ?? []);
        $storageBasePath = $this->fileCleanupService->getStorageBasePath();
        $files           = $this->fileCleanupService->findFilesByUids($uids);

        $tmpFile = tempnam(sys_get_temp_dir(), 'gccleanup_');
        $zip     = new \ZipArchive();

        if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup')
            );
        }

        foreach ($files as $file) {
            $abs = rtrim($storageBasePath, '/') . '/' . ltrim($file['identifier'], '/');
            if (is_file($abs)) {
                $zip->addFile($abs, ltrim($file['identifier'], '/'));
            }
        }
        $zip->close();

        $content = (string)file_get_contents($tmpFile);
        unlink($tmpFile);

        $stream = new Stream('php://temp', 'r+');
        $stream->write($content);
        $stream->rewind();

        return (new Response())
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="cleanup-backup-' . date('Y-m-d') . '.zip"')
            ->withHeader('Content-Length', (string)strlen($content))
            ->withBody($stream);
    }

    /**
     * Thumbnail URL for an unused-file row, or null when it's not an image, is physically
     * missing, or the sys_file record is otherwise broken (e.g. points at a dead storage).
     */
    private function getThumbnailUrl(array $file): ?string
    {
        if (!empty($file['physicallyMissing'])) {
            return null;
        }
        try {
            $fileObject = $this->resourceFactory->getFileObject((int)$file['uid']);
        } catch (\Exception) {
            return null;
        }
        if (!$fileObject->isImage()) {
            return null;
        }
        $processedFile = $fileObject->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, [
            'maxWidth'  => 150,
            'maxHeight' => 150,
        ]);
        return $processedFile->getPublicUrl() ?: null;
    }

    private function resolveFolder(string $combinedIdentifier): ?Folder
    {
        if ($combinedIdentifier === '') {
            return null;
        }
        try {
            $object = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            return $object instanceof Folder ? $object : null;
        } catch (FolderDoesNotExistException | \InvalidArgumentException) {
            return null;
        }
    }

    private function getFolderPrefix(?Folder $folder): string
    {
        if ($folder === null) {
            return '';
        }
        $identifier = $folder->getIdentifier(); // e.g. "/images/" or "/"
        return ($identifier === '/') ? '' : $identifier;
    }
}
