<?php

declare(strict_types=1);

namespace Anubit\Filefix\Controller;

use Anubit\Filefix\Service\ActionLogger;
use Anubit\Filefix\Service\DuplicateFileService;
use Anubit\Filefix\Service\FileUsageDeepCheckService;
use Anubit\Filefix\Utility\Labels;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Read-only duplicate report (route filefix_cleanup.duplicates), opened from the file list.
 * Admin only: the report shows paths outside file mounts and records on any page.
 */
class DuplicatesController
{
    private const STORAGE_UID = 1;
    private const PER_PAGE    = 25;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
        private readonly DuplicateFileService $duplicateFileService,
        private readonly FileUsageDeepCheckService $deepCheckService,
        private readonly ActionLogger $actionLogger,
    ) {}

    /**
     * AJAX route ajax_filefix_deletefile (POST): deletes one file after the deep check passes again
     * on the server. The deep check result shown in the browser is never trusted on its own.
     */
    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            return new JsonResponse(['error' => Labels::get('common.error.adminOnly')], 403);
        }
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => Labels::get('common.error.postRequired')], 405);
        }
        $fileUid = (int)(((array)$request->getParsedBody())['file'] ?? 0);
        $check = $this->deepCheckService->check($fileUid);
        if (!$check['found']) {
            return new JsonResponse(['error' => Labels::get('common.error.fileNotFound')], 404);
        }
        if (!$check['safeToDelete']) {
            return new JsonResponse(['error' => Labels::get('dup.error.inUse', $check['activeUsages'])], 409);
        }
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            $identifier = $file->getIdentifier();
            $name = $file->getName();
            $storageUid = $file->getStorage()->getUid();
            // FAL removes the physical file, sys_file, its metadata and processed files
            $file->getStorage()->deleteFile($file);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => Labels::get('dup.error.deleteFailed', $e->getMessage())], 500);
        }
        $this->actionLogger->log(
            ActionLogger::ACTION_DELETE_DUPLICATE,
            $identifier,
            $name,
            $storageUid,
            $fileUid,
            'Duplicate report, deep check passed: ' . $check['searchedTables'] . ' tables searched, 0 usages'
        );
        return new JsonResponse(['deleted' => true, 'identifier' => $identifier]);
    }

    /**
     * AJAX route ajax_filefix_deepcheck: live check of one file against the whole database.
     */
    public function deepCheckAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            return new JsonResponse(['error' => Labels::get('common.error.adminOnly')], 403);
        }
        $fileUid = (int)($request->getQueryParams()['file'] ?? 0);
        $result = $this->deepCheckService->check($fileUid);
        if ($result['found']) {
            // Edit links for existing, not deleted records; the browser adds the returnUrl to the report
            foreach (['references' => 'parentDeleted', 'indexEntries' => 'deleted', 'textMatches' => 'deleted'] as $key => $deletedKey) {
                foreach ($result[$key] as &$entry) {
                    $entry['editUrl'] = isset($GLOBALS['TCA'][$entry['table']]) && $entry['recordUid'] > 0 && !$entry[$deletedKey]
                        ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', ['edit' => [$entry['table'] => [$entry['recordUid'] => 'edit']]])
                        : '';
                }
                unset($entry);
            }
        }
        return new JsonResponse($result, $result['found'] ? 200 : 404);
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        if (!($GLOBALS['BE_USER']->isAdmin() ?? false)) {
            $moduleTemplate->assign('accessDenied', true);
            return $moduleTemplate->renderResponse('Duplicates/Index');
        }

        $queryParams  = $request->getQueryParams();
        $folderId     = (string)($queryParams['id'] ?? '');
        $folder       = $this->resolveFolder($folderId);
        $folderPrefix = ($folder === null || $folder->getIdentifier() === '/') ? '' : $folder->getIdentifier();

        if (($queryParams['format'] ?? '') === 'csv') {
            return $this->csvResponse($folderPrefix);
        }

        // Only copies that exist on disk, sizes from disk (see DuplicateFileService)
        $page        = max(1, (int)($queryParams['page'] ?? 1));
        $report      = $this->duplicateFileService->getReport(self::STORAGE_UID, $folderPrefix, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $summary     = $report['summary'];
        $totalGroups = $summary['groups'];
        $totalPages  = max(1, (int)ceil($totalGroups / self::PER_PAGE));
        if ($page > $totalPages) {
            // Page beyond the end (e.g. after deleting the last copies of a page): show the last page
            $page   = $totalPages;
            $report = $this->duplicateFileService->getReport(self::STORAGE_UID, $folderPrefix, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }
        $offset = ($page - 1) * self::PER_PAGE;
        $groups = $report['groups'];
        foreach ($groups as &$group) {
            // Lazy-loaded via AJAX: processing inline waited for every large original
            $group['thumbnailUrl'] = $this->isImage($group['firstUid'])
                ? (string)$this->uriBuilder->buildUriFromRoute('ajax_filefix_thumbnail', ['file' => $group['firstUid']])
                : null;
            foreach ($group['copiesList'] as &$copy) {
                $copy['metadataEditUrl'] = $copy['metadataUid'] > 0 ? $this->getEditUrl('sys_file_metadata', $copy['metadataUid'], $request) : '';
                $copy['deepCheckUrl'] = (string)$this->uriBuilder->buildUriFromRoute('ajax_filefix_deepcheck', ['file' => $copy['uid']]);
                $copy['deleteUrl'] = (string)$this->uriBuilder->buildUriFromRoute('ajax_filefix_deletefile');
                foreach ($copy['references'] as &$reference) {
                    $reference['editUrl'] = $reference['recordExists'] ? $this->getEditUrl($reference['table'], $reference['recordUid'], $request) : '';
                }
                unset($reference);
            }
            unset($copy);
        }
        unset($group);

        $baseParams = ['id' => $folderId];
        if ($folder !== null) {
            $moduleTemplate->getDocHeaderComponent()->setMetaInformationForResource($folder);
        }
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $iconSize = class_exists(IconSize::class) ? IconSize::SMALL : 'small';
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('media_management', $baseParams))
                ->setTitle(Labels::get('common.backToFileList'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-list-collapse', $iconSize)),
            ButtonBar::BUTTON_POSITION_LEFT,
            1
        );
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.duplicates', $baseParams + ['format' => 'csv']))
                ->setTitle(Labels::get('dup.exportCsv'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-download', $iconSize)),
            ButtonBar::BUTTON_POSITION_LEFT,
            2
        );

        $moduleTemplate->assignMultiple([
            'jsLabels'     => Labels::many(['common.close', 'common.cancel', 'common.loading', 'common.unknownError', 'common.path', 'common.editRecord', 'common.hidden', 'common.lang', 'common.workspace', 'common.error.fileNotFound', 'dup.usages.field', 'dup.usages.record', 'deep.noneFound', 'deep.deletedRecord', 'deep.safe', 'deep.inUse', 'deep.otherRecords', 'deep.sysFileUid', 'deep.references', 'deep.refindex', 'deep.textSearch', 'deep.table', 'deep.notes', 'deep.textTruncated', 'deep.searchedFor', 'deep.searchedTables', 'deep.skippedTables', 'deep.notCovered', 'deep.deleteFile', 'deep.deleteConfirm', 'deep.deleting', 'deep.notDeleted', 'deep.searching', 'deep.failed', 'deep.title']),
            'groups'       => $groups,
            'totalGroups'  => $totalGroups,
            'totalWasted'  => $summary['wasted'],
            'recordsNotOnDisk' => $summary['recordsNotOnDisk'],
            'folderName'   => $folder ? ($folder->getName() ?: $folder->getStorage()->getName()) : '',
            'currentPage'  => $page,
            'totalPages'   => $totalPages,
            'rangeFrom'    => $totalGroups > 0 ? $offset + 1 : 0,
            'rangeTo'      => min($offset + self::PER_PAGE, $totalGroups),
            'prevPageUrl'  => $page > 1 ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.duplicates', $baseParams + ['page' => $page - 1]) : '',
            'nextPageUrl'  => $page < $totalPages ? (string)$this->uriBuilder->buildUriFromRoute('filefix_cleanup.duplicates', $baseParams + ['page' => $page + 1]) : '',
            'storagePublicBase' => $this->getStoragePublicBase(),
        ]);
        return $moduleTemplate->renderResponse('Duplicates/Index');
    }

    /**
     * One row per file reference (or per copy without references) for all groups in the folder.
     */
    private function csvResponse(string $folderPrefix): ResponseInterface
    {
        $groups = $this->duplicateFileService->getReport(self::STORAGE_UID, $folderPrefix)['groups'];
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['sha1', 'copies', 'wasted_bytes', 'file_uid', 'path', 'suggested_keeper', 'file_title', 'file_alternative', 'file_description', 'soft_references', 'table', 'field', 'record_uid', 'record_title', 'page_uid', 'page_title', 'language', 'hidden', 'own_alternative', 'own_title'], ',', '"', '\\');
        foreach ($groups as $group) {
            foreach ($group['copiesList'] as $copy) {
                $fileColumns = [
                    $group['sha1'], $group['copies'], $group['wasted'], $copy['uid'], $copy['identifier'],
                    $copy['isKeeper'] ? 'yes' : 'no',
                    $copy['metadata']['title'], $copy['metadata']['alternative'], $copy['metadata']['description'],
                    $copy['softReferences'],
                ];
                if ($copy['references'] === []) {
                    fputcsv($handle, array_merge($fileColumns, array_fill(0, 10, '')), ',', '"', '\\');
                    continue;
                }
                foreach ($copy['references'] as $reference) {
                    fputcsv($handle, array_merge($fileColumns, [
                        $reference['table'], $reference['field'], $reference['recordUid'], $reference['recordTitle'],
                        $reference['pageUid'], $reference['pageTitle'], $reference['language'],
                        $reference['hidden'] ? 'yes' : 'no',
                        $reference['ownAlternative'] ? 'yes' : 'no',
                        $reference['ownTitle'] ? 'yes' : 'no',
                    ]), ',', '"', '\\');
                }
            }
        }
        rewind($handle);
        $body = new Stream('php://temp', 'rw');
        $body->write(stream_get_contents($handle));
        fclose($handle);

        return (new Response())
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="filefix-duplicates-' . date('Y-m-d') . '.csv"')
            ->withBody($body);
    }

    private function getEditUrl(string $table, int $uid, ServerRequestInterface $request): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit'      => [$table => [$uid => 'edit']],
            'returnUrl' => (string)$request->getUri(),
        ]);
    }

    private function isImage(int $fileUid): bool
    {
        try {
            return $this->resourceFactory->getFileObject($fileUid)->isImage();
        } catch (\Exception) {
            return false;
        }
    }

    private function getStoragePublicBase(): string
    {
        // StorageRepository: ResourceFactory::getStorageObject() was removed in TYPO3 v14
        $basePath = $this->storageRepository->findByUid(self::STORAGE_UID)?->getConfiguration()['basePath'] ?? 'fileadmin';
        return '/' . trim((string)$basePath, '/');
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
