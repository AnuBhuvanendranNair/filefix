<?php

declare(strict_types=1);

namespace Anubit\Filefix\Controller;

use Anubit\Filefix\Service\FilefixSettings;
use Anubit\Filefix\Service\ImageResizeService;
use Anubit\Filefix\Service\OversizedImageService;
use Anubit\Filefix\Utility\Labels;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Read-only report of oversized images in the current folder (route filefix_cleanup.oversized),
 * opened from the file list. Admin only, like the duplicate report.
 */
class OversizedController
{
    private const STORAGE_UID = 1;
    private const PER_PAGE    = 25;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
        private readonly OversizedImageService $oversizedImageService,
        private readonly FilefixSettings $settings,
        private readonly ImageResizeService $imageResizeService,
        private readonly FlashMessageService $flashMessageService,
    ) {}

    /**
     * AJAX route ajax_filefix_resizeimage (POST): dry run (dryRun=1) or real resize of one image
     * to the limits from the extension settings. No backup, no log (decided for P1).
     */
    public function resizeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            return new JsonResponse(['error' => Labels::get('common.error.adminOnly')], 403);
        }
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => Labels::get('common.error.postRequired')], 405);
        }
        $body = (array)$request->getParsedBody();
        $result = $this->imageResizeService->resize((int)($body['file'] ?? 0), ($body['dryRun'] ?? '1') !== '0');
        if ($result['applied'] ?? false) {
            // Shown by the module template on the reload after the resize (stored in the backend session)
            $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
                Labels::get(
                    'resize.flash.text',
                    $result['identifier'],
                    $result['width'],
                    $result['height'],
                    $this->formatBytes($result['size']),
                    $result['newWidth'],
                    $result['newHeight'],
                    $this->formatBytes($result['newSize']),
                    $this->formatBytes($result['saving'])
                ),
                Labels::get('resize.flash.title'),
                ContextualFeedbackSeverity::OK,
                true
            ));
        }
        return new JsonResponse($result + [
            'maxWidth'  => $this->settings->getImageMaxWidth(),
            'maxHeight' => $this->settings->getImageMaxHeight(),
        ], $result['found'] ? 200 : 404);
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            $moduleTemplate->assign('accessDenied', true);
            return $moduleTemplate->renderResponse('Oversized/Index');
        }

        $queryParams  = $request->getQueryParams();
        $folderId     = (string)($queryParams['id'] ?? '');
        $folder       = $this->resolveFolder($folderId);
        $folderPrefix = ($folder === null || $folder->getIdentifier() === '/') ? '' : $folder->getIdentifier();
        // Defaults from the extension settings; the form can override them for this view only
        $maxWidth     = $this->clampDimension($queryParams['maxWidth'] ?? null, $this->settings->getImageMaxWidth());
        $maxHeight    = $this->clampDimension($queryParams['maxHeight'] ?? null, $this->settings->getImageMaxHeight());
        $fileTypes    = $this->settings->getImageFileTypes();
        // Checkbox: absent on submit means "off"; absent on first load means default "on"
        $recursive    = isset($queryParams['submitted']) ? !empty($queryParams['recursive']) : true;

        // Only images that exist on disk, sizes from disk (see OversizedImageService)
        $page   = max(1, (int)($queryParams['page'] ?? 1));
        $report = $this->oversizedImageService->getReport(self::STORAGE_UID, $folderPrefix, $recursive, $maxWidth, $maxHeight, $fileTypes, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $summary    = $report['summary'];
        $totalPages = max(1, (int)ceil($summary['count'] / self::PER_PAGE));
        if ($page > $totalPages) {
            // Page beyond the end (e.g. after resizing the last images of a page): show the last page
            $page   = $totalPages;
            $report = $this->oversizedImageService->getReport(self::STORAGE_UID, $folderPrefix, $recursive, $maxWidth, $maxHeight, $fileTypes, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }
        $offset = ($page - 1) * self::PER_PAGE;
        $images = $report['images'];
        $resizeTypes = $this->settings->getImageResizeTypes();
        foreach ($images as &$image) {
            // Lazy-loaded via AJAX: processing inline waited for every large original
            $image['thumbnailUrl'] = (string)$this->uriBuilder->buildUriFromRoute('ajax_filefix_thumbnail', ['file' => $image['uid']]);
            $image['resizable'] = in_array($image['extension'], $resizeTypes, true);
        }
        unset($image);

        $baseParams = ['id' => $folderId, 'maxWidth' => $maxWidth, 'maxHeight' => $maxHeight, 'submitted' => 1]
            + ($recursive ? ['recursive' => 1] : []);
        if ($folder !== null) {
            $moduleTemplate->getDocHeaderComponent()->setMetaInformationForResource($folder);
        }
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('media_management', ['id' => $folderId]))
                ->setTitle(Labels::get('common.backToFileList'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-list-collapse', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
            ButtonBar::BUTTON_POSITION_LEFT,
            1
        );

        // GET form: the route token lives in the action URL query and would be dropped by the browser,
        // so all query parameters of the action URL are passed as hidden fields
        $formUrl = (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.oversized', ['id' => $folderId]);
        parse_str((string)parse_url($formUrl, PHP_URL_QUERY), $formHiddenFields);

        $moduleTemplate->assignMultiple([
            'jsLabels'         => Labels::many(['common.close', 'common.loading', 'common.unknownError', 'over.col.dimensions', 'over.js.testResize', 'over.js.fileSize', 'over.js.now', 'over.js.after', 'over.js.saving', 'over.js.warning', 'over.js.resizeNow', 'over.js.notResized', 'over.js.failed', 'over.js.testing', 'over.js.resizing', 'over.js.title']),
            'images'           => $images,
            'summary'          => $summary,
            'savingPercent'    => $summary['size'] > 0 ? (int)round($summary['saving'] / $summary['size'] * 100) : 0,
            'maxWidth'         => $maxWidth,
            'maxHeight'        => $maxHeight,
            'recursive'        => $recursive,
            'fileTypes'        => implode(', ', $fileTypes),
            'resizeTypes'      => implode(', ', $resizeTypes),
            'resizeUrl'        => (string)$this->uriBuilder->buildUriFromRoute('ajax_filefix_resizeimage'),
            'settingsMaxWidth' => $this->settings->getImageMaxWidth(),
            'settingsMaxHeight' => $this->settings->getImageMaxHeight(),
            'isDefaultSize'    => $maxWidth === $this->settings->getImageMaxWidth() && $maxHeight === $this->settings->getImageMaxHeight(),
            'folderName'       => $folder ? ($folder->getName() ?: $folder->getStorage()->getName()) : '',
            'formAction'       => strtok($formUrl, '?'),
            'formHiddenFields' => $formHiddenFields,
            'currentPage'      => $page,
            'totalPages'       => $totalPages,
            'rangeFrom'        => $summary['count'] > 0 ? $offset + 1 : 0,
            'rangeTo'          => min($offset + self::PER_PAGE, $summary['count']),
            'prevPageUrl'      => $page > 1 ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.oversized', $baseParams + ['page' => $page - 1]) : '',
            'nextPageUrl'      => $page < $totalPages ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.oversized', $baseParams + ['page' => $page + 1]) : '',
            'storagePublicBase' => '/' . trim((string)($this->storageRepository->findByUid(self::STORAGE_UID)?->getConfiguration()['basePath'] ?? 'fileadmin'), '/'),
        ]);
        return $moduleTemplate->renderResponse('Oversized/Index');
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, '.', '') . ' MB'
            : number_format($bytes / 1024, 1, '.', '') . ' KB';
    }

    private function clampDimension(mixed $value, int $default): int
    {
        $value = (int)$value;
        return $value >= 100 && $value <= 10000 ? $value : $default;
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
}
