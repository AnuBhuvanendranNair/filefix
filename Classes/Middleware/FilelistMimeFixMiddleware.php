<?php

declare(strict_types=1);

namespace Anubit\Filefix\Middleware;

use Anubit\Filefix\Service\MimeTypeService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\Event\ResolveVirtualJavaScriptImportEvent;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FilelistMimeFixMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly ModuleProvider $moduleProvider,
        private readonly FlashMessageService $flashMessageService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly IconFactory $iconFactory,
        private readonly MimeTypeService $mimeTypeService,
        private readonly ResourceFactory $resourceFactory,
        private readonly ListenerProvider $listenerProvider,
    ) {}

    /**
     * Our mimefix_scan/mimefix_fix branches render a response directly and never call
     * $handler->handle($request), so core's own JavaScriptLabelImportMapEntryResolver
     * middleware (further down the chain) never runs — its listener is what resolves the
     * "~labels/" virtual import specifier into a real URL. Without it, that entry is silently
     * dropped from the import map and every "@typo3/backend/*" module that imports labels
     * (e.g. modal.js) fails to load in the browser. Register the same listener ourselves.
     */
    private function ensureLabelImportMapListenerRegistered(): void
    {
        // TYPO3 v14+ only; v12/v13 have no virtual "~labels/" import specifier
        if (!class_exists(ResolveVirtualJavaScriptImportEvent::class)
            || !class_exists(\TYPO3\CMS\Backend\Middleware\JavaScriptLabelImportMapEntryResolver::class)
        ) {
            return;
        }
        $this->listenerProvider->addListener(
            ResolveVirtualJavaScriptImportEvent::class,
            \TYPO3\CMS\Backend\Middleware\JavaScriptLabelImportMapEntryResolver::class,
            'resolveVirtualLabelImport'
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        if (!($route instanceof Route) || $route->getPath() !== '/module/file/list') {
            return $handler->handle($request);
        }

        $queryParams  = $request->getQueryParams();
        $parsedBody   = $request->getParsedBody();

        if ($request->getMethod() === 'POST' && !empty($parsedBody['mimefix_fix'])) {
            return $this->handleFix($request);
        }

        if (!empty($queryParams['mimefix_scan'])) {
            return $this->renderScanView($request);
        }

        // Normal filelist request — inject scan button JS inline (no token needed; middleware intercepts before CSRF check)
        $folderId = (string)($request->getQueryParams()['id'] ?? '');
        if ($folderId === '') {
            // No ?id= (module opened from menu): filelist shows the default storage root
            try {
                // TYPO3 v14 removed ResourceFactory::getDefaultStorage(); use StorageRepository there
                $defaultStorage = method_exists($this->resourceFactory, 'getDefaultStorage')
                    ? $this->resourceFactory->getDefaultStorage()
                    : GeneralUtility::makeInstance(StorageRepository::class)->getDefaultStorage();
                $folderId = (string)$defaultStorage?->getRootLevelFolder()->getCombinedIdentifier();
            } catch (\Exception) {
                $folderId = '';
            }
        }
        if ($folderId !== '') {
            $scanHref = '/typo3/module/file/list?id=' . rawurlencode($folderId) . '&mimefix_scan=1';
            $this->pageRenderer->addJsInlineCode('filefix-toolbar', $this->buildToolbarScript($scanHref), false, false, true);
        }

        return $handler->handle($request);
    }

    private function renderScanView(ServerRequestInterface $request): ResponseInterface
    {
        $this->ensureLabelImportMapListenerRegistered();

        $folderId = (string)($request->getQueryParams()['id'] ?? '');
        $folder   = $this->resolveFolder($folderId);

        if ($folder === null) {
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute('media_management', $folderId !== '' ? ['id' => $folderId] : [])
            );
        }

        $absolutePath = $this->getFolderAbsolutePath($folder);
        if ($absolutePath === '' || !is_dir($absolutePath)) {
            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folderId])
            );
        }

        $storage     = $folder->getStorage();
        $storageCfg  = $storage->getConfiguration();
        $storageBase = rtrim(\TYPO3\CMS\Core\Core\Environment::getPublicPath(), '/')
            . '/' . ltrim($storageCfg['basePath'] ?? 'fileadmin', '/');

        $mismatches = $this->mimeTypeService->scanDirectory($absolutePath, true, $storage->getUid(), $storageBase);

        $GLOBALS['TYPO3_REQUEST'] = $request;

        $view           = $this->backendViewFactory->create($request, ['anubit/filefix']);
        $moduleTemplateArgs = [
            $this->pageRenderer,
            $this->iconFactory,
            $this->uriBuilder,
            $this->moduleProvider,
            $this->flashMessageService,
            $this->extensionConfiguration,
            $view,
        ];
        // TYPO3 v14 added ComponentFactory before $request; v12/v13 do not have it
        if (class_exists(ComponentFactory::class)) {
            $moduleTemplateArgs[] = GeneralUtility::makeInstance(ComponentFactory::class);
        }
        $moduleTemplateArgs[] = $request;
        $moduleTemplate = new ModuleTemplate(...$moduleTemplateArgs);

        $moduleTemplate->getDocHeaderComponent()->setMetaInformationForResource($folder);

        $backUrl      = (string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folderId]);
        $scanAgainUrl = (string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folderId, 'mimefix_scan' => '1']);
        $fixActionUrl = (string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folderId]);

        $buttonBar  = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref($backUrl)
                ->setTitle('Back to file list')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-list-collapse', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
            ButtonBar::BUTTON_POSITION_LEFT,
            1
        );
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref($scanAgainUrl)
                ->setTitle('Scan again')
                ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
            ButtonBar::BUTTON_POSITION_RIGHT
        );

        $moduleTemplate->assignMultiple([
            'folderIdentifier'  => $folderId,
            'folderName'        => $folder->getName() ?: $folder->getStorage()->getName(),
            'mismatches'        => $mismatches,
            'mismatchCount'     => count($mismatches),
            'selectableCount'   => count(array_filter($mismatches, fn($m) => $m['selectable'])),
            'fixActionUrl'      => $fixActionUrl,
        ]);

        return $moduleTemplate->renderResponse('MimeFix/Scan');
    }

    private function handleFix(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody    = $request->getParsedBody();
        $files         = (array)($parsedBody['files'] ?? []);
        $folderId      = (string)($parsedBody['folderId'] ?? $request->getQueryParams()['id'] ?? '');
        $fileadminPath = $this->mimeTypeService->getFileadminPath();
        $realFileadmin = realpath($fileadminPath);
        $errors        = [];
        $fixed         = 0;

        $folder  = $this->resolveFolder($folderId);
        $storage = $folder?->getStorage();

        foreach ($files as $filePath) {
            $realPath = realpath((string)$filePath);
            if (!$realPath || !$realFileadmin || !str_starts_with($realPath, $realFileadmin)) {
                $errors[] = basename((string)$filePath) . ': path rejected (security check)';
                continue;
            }

            // Convert content if it still doesn't match the extension; skip conversion
            // when the file was already fixed externally (DB-only sync case).
            if ($this->mimeTypeService->needsConversion($realPath)) {
                [$success, $errorDetail] = $this->mimeTypeService->fixFileWithError($realPath);
                if (!$success) {
                    $errors[] = basename($realPath) . ': ' . $errorDetail;
                    continue;
                }
            }

            if ($storage === null) {
                $errors[] = basename($realPath) . ': storage could not be resolved';
                continue;
            }

            try {
                $fileIdentifier = '/' . ltrim(str_replace($realFileadmin, '', $realPath), '/');
                $ext            = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
                if ($this->mimeTypeService->isDbOnlyExtension($ext)) {
                    // FAL indexer would re-detect wrong mime for text types — force DB value directly.
                    $storageCfg  = $storage->getConfiguration();
                    $storageBase = rtrim(\TYPO3\CMS\Core\Core\Environment::getPublicPath(), '/')
                        . '/' . ltrim($storageCfg['basePath'] ?? 'fileadmin', '/');
                    if (!$this->mimeTypeService->fixDbMimeType($realPath, $storage->getUid(), $storageBase)) {
                        $errors[] = basename($realPath) . ': not indexed in sys_file — index the file via TYPO3 file module first';
                        continue;
                    }
                } else {
                    $file = $storage->getFile($fileIdentifier);
                    GeneralUtility::makeInstance(Indexer::class, $storage)->updateIndexEntry($file);
                }
            } catch (\Exception $e) {
                $errors[] = basename($realPath) . ': ' . $e->getMessage();
                continue;
            }

            $fixed++;
        }

        $queue = $this->flashMessageService->getMessageQueueByIdentifier();
        if ($fixed > 0) {
            $queue->enqueue(new FlashMessage(
                $fixed . ' file(s) processed and sys_file record(s) updated.',
                'MIME fix',
                ContextualFeedbackSeverity::OK,
                true
            ));
        }
        foreach ($errors as $msg) {
            $queue->enqueue(new FlashMessage(
                $msg,
                'MIME fix failed',
                ContextualFeedbackSeverity::ERROR,
                true
            ));
        }

        $redirectParams = ['mimefix_scan' => '1'];
        if ($folderId !== '') {
            $redirectParams['id'] = $folderId;
        }
        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute('media_management', $redirectParams)
        );
    }

    private function resolveFolder(string $combinedIdentifier): ?Folder
    {
        if ($combinedIdentifier === '') {
            return null;
        }
        try {
            $object = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            return $object instanceof Folder ? $object : null;
        } catch (FolderDoesNotExistException|\InvalidArgumentException) {
            return null;
        }
    }

    private function getFolderAbsolutePath(Folder $folder): string
    {
        $config   = $folder->getStorage()->getConfiguration();
        $basePath = rtrim(\TYPO3\CMS\Core\Core\Environment::getPublicPath(), '/')
            . '/' . ltrim($config['basePath'] ?? 'fileadmin', '/');
        return rtrim($basePath, '/') . $folder->getIdentifier();
    }

    private function buildToolbarScript(string $scanHref): string
    {
        $hrefJson = json_encode($scanHref, JSON_UNESCAPED_SLASHES);
        return <<<JS
(function () {
    var inject = function () {
        if (document.getElementById('mimefix-scan-btn')) { return; }
        // v14: .module-docheader-buttons .module-docheader-column-grow, v12/v13: .module-docheader-bar-buttons .module-docheader-bar-column-left
        var container = document.querySelector('.module-docheader-buttons .module-docheader-column-grow')
            || document.querySelector('.module-docheader-bar-buttons .module-docheader-bar-column-left');
        if (!container) { return; }
        var toolbar = container.querySelector('.btn-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.className = 'btn-toolbar';
            toolbar.setAttribute('role', 'toolbar');
            container.appendChild(toolbar);
        }
        var btn = document.createElement('a');
        btn.id        = 'mimefix-scan-btn';
        btn.href      = {$hrefJson};
        btn.className = 'btn btn-default btn-sm';
        btn.title     = 'Scan folder for MIME type mismatches';
        var icon = document.createElement('typo3-backend-icon');
        icon.setAttribute('identifier', 'actions-search');
        icon.setAttribute('size', 'small');
        btn.appendChild(icon);
        btn.appendChild(document.createTextNode(' Scan MIME'));
        // Loading overlay (same markup as the File Cleanup module): the scan runs server-side
        // before any response, without it the file list looks frozen
        btn.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            var overlay = document.getElementById('filefix-loading');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'filefix-loading';
                overlay.className = 'position-fixed top-0 start-0 w-100 h-100 flex-column align-items-center justify-content-center gap-2 bg-body bg-opacity-75';
                // Inline positioning: TYPO3 v14 backend CSS no longer ships the position/inset/opacity utilities
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:1050;background-color:rgba(var(--bs-body-bg-rgb, 255, 255, 255), .75)';
                var spinner = document.createElement('typo3-backend-icon');
                spinner.setAttribute('identifier', 'spinner-circle');
                spinner.setAttribute('size', 'large');
                var text = document.createElement('span');
                text.className = 'text-body-secondary';
                text.textContent = 'Scanning for MIME type mismatches…';
                overlay.appendChild(spinner);
                overlay.appendChild(text);
                document.body.appendChild(overlay);
            }
            overlay.classList.remove('d-none');
            overlay.classList.add('d-flex');
        });
        toolbar.appendChild(btn);
    };
    // Back/forward cache restores the page with the overlay still visible
    window.addEventListener('pageshow', function (e) {
        var overlay = document.getElementById('filefix-loading');
        if (e.persisted && overlay) {
            overlay.classList.add('d-none');
            overlay.classList.remove('d-flex');
        }
    });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inject);
    } else {
        inject();
    }
}());
JS;
    }
}
