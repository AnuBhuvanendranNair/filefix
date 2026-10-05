<?php

declare(strict_types=1);

namespace Anubit\Filefix\Controller;

use Anubit\Filefix\Repository\LogRepository;
use Anubit\Filefix\Repository\QuarantineRepository;
use Anubit\Filefix\Service\ActionLogger;
use Anubit\Filefix\Service\FileCleanupService;
use Anubit\Filefix\Service\QuarantineFlushService;
use Anubit\Filefix\Service\QuarantineRestoreService;
use Anubit\Filefix\Service\QuarantineScanService;
use Anubit\Filefix\Service\QuarantineValidationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

class QuarantineController
{
    private const ROUTE       = 'filefix_quarantine';
    private const VALID_PER_PAGE = [20, 50, 100];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly FlashMessageService $flashMessageService,
        private readonly QuarantineRepository $quarantineRepository,
        private readonly QuarantineFlushService $flushService,
        private readonly QuarantineValidationService $validationService,
        private readonly QuarantineRestoreService $restoreService,
        private readonly FileCleanupService $fileCleanupService,
        private readonly ActionLogger $actionLogger,
        private readonly LogRepository $logRepository,
        private readonly QuarantineScanService $scanService,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return $this->handleBulkAction($request);
        }

        $q        = $request->getQueryParams();
        $view     = ($q['view'] ?? '') === 'log' ? 'log' : 'quarantine';
        $perPage  = (int)($q['perPage'] ?? 20);
        $perPage  = in_array($perPage, self::VALID_PER_PAGE, true) ? $perPage : 20;
        $page     = max(1, (int)($q['page'] ?? 1));
        $folderId = (string)($q['id'] ?? '');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $buttonBar      = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($view === 'log') {
            $logTotal      = $this->logRepository->countTotal();
            $logTotalPages = max(1, (int)ceil($logTotal / $perPage));
            $page          = min($page, $logTotalPages);
            $offset        = ($page - 1) * $perPage;
            $logEntries    = $this->logRepository->findRecent($perPage, $offset);

            $baseParams = array_filter(['view' => 'log', 'perPage' => $perPage, 'id' => $folderId]);

            $perPageOptions = [];
            foreach (self::VALID_PER_PAGE as $pp) {
                $perPageOptions[] = [
                    'value'  => $pp,
                    'url'    => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['perPage' => $pp])),
                    'active' => $pp === $perPage,
                ];
            }

            $buttonBar->addButton(
                $buttonBar->makeLinkButton()
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $baseParams))
                    ->setTitle('Refresh')
                    ->setShowLabelText(true)
                    ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
                ButtonBar::BUTTON_POSITION_RIGHT
            );

            $moduleTemplate->assignMultiple([
                'view'          => 'log',
                'folderId'      => $folderId,
                'logEntries'    => $logEntries,
                'logTotal'      => $logTotal,
                'currentPage'   => $page,
                'totalPages'    => $logTotalPages,
                'perPage'       => $perPage,
                'rangeFrom'     => $logTotal > 0 ? $offset + 1 : 0,
                'rangeTo'       => min($offset + $perPage, $logTotal),
                'prevPageUrl'   => $page > 1
                    ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $page - 1]))
                    : null,
                'nextPageUrl'   => $page < $logTotalPages
                    ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $page + 1]))
                    : null,
                'firstPageUrl'  => $page > 1
                    ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => 1]))
                    : null,
                'lastPageUrl'   => $page < $logTotalPages
                    ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $logTotalPages]))
                    : null,
                'pageNumbers'   => $this->buildPageNumbers($page, $logTotalPages, $baseParams),
                'perPageOptions'  => $perPageOptions,
                'moduleUrl'       => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            ]);

            return $moduleTemplate->renderResponse('Quarantine/Index');
        }

        // ── Quarantine view ───────────────────────────────────────────────────
        $statusFilter = (string)($q['status'] ?? '');
        $reasonFilter = (string)($q['reason'] ?? '');
        $scanIdFilter = (string)($q['scan_id'] ?? '');
        $levelFilter  = (int)($q['level'] ?? 0); // 0 = all levels (no depth cap)
        $levelFilter  = in_array($levelFilter, [1, 2, 3], true) ? $levelFilter : 0;
        $folder       = $this->resolveFolder($folderId);
        $folderFilter = $this->getFolderPrefix($folder);
        $folderName   = $folder ? ($folder->getName() ?: $folder->getStorage()->getName()) : '';

        if ($folder !== null) {
            $moduleTemplate->getDocHeaderComponent()->setMetaInformationForResource($folder);
        }

        $filters = array_filter([
            'status'      => $statusFilter ?: null,
            'reason'      => $reasonFilter ?: null,
            'scan_id'     => $scanIdFilter ?: null,
            'folder'      => $folderFilter ?: null,
        ]);

        if ($levelFilter > 0) {
            // Depth filtering happens in PHP (identifier segment count relative to the
            // selected folder) — cap the fetch to guard against extremely large queues.
            $all = $this->quarantineRepository->findForList($filters, 20000, 0);
            $all = array_values(array_filter(
                $all,
                fn(array $rec): bool => $this->folderDepth((string)$rec['identifier'], $folderFilter) <= $levelFilter
            ));

            $totalCount = count($all);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;
            $records    = array_slice($all, $offset, $perPage);
        } else {
            $totalCount = $this->quarantineRepository->countForList($filters);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;
            $records    = $this->quarantineRepository->findForList($filters, $perPage, $offset);
        }

        $zeroEntry    = ['count' => 0, 'size' => 0];
        $statusCounts = array_merge(
            array_fill_keys(
                [
                    QuarantineRepository::STATUS_CANDIDATE,
                    QuarantineRepository::STATUS_QUARANTINED,
                    QuarantineRepository::STATUS_SKIPPED,
                    QuarantineRepository::STATUS_RESTORED,
                    QuarantineRepository::STATUS_FLUSHED,
                    QuarantineRepository::STATUS_FAILED,
                ],
                $zeroEntry
            ),
            $this->quarantineRepository->getStatusCounts($filters)
        );

        $reclaimableSize = 0;
        foreach ([QuarantineRepository::STATUS_CANDIDATE, QuarantineRepository::STATUS_QUARANTINED] as $s) {
            $reclaimableSize += (int)($statusCounts[$s]['size'] ?? 0);
        }

        $baseParams = array_filter([
            'status'      => $statusFilter,
            'reason'      => $reasonFilter,
            'scan_id'     => $scanIdFilter,
            'level'       => $levelFilter ?: null,
            'id'          => $folderId,
            'perPage'     => $perPage,
        ]);

        $perPageOptions = [];
        foreach (self::VALID_PER_PAGE as $pp) {
            $perPageOptions[] = [
                'value'  => $pp,
                'url'    => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['perPage' => $pp])),
                'active' => $pp === $perPage,
            ];
        }

        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $baseParams))
                ->setTitle('Refresh')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-refresh', class_exists(IconSize::class) ? IconSize::SMALL : 'small')),
            ButtonBar::BUTTON_POSITION_RIGHT
        );

        $moduleTemplate->assignMultiple([
            'view'             => 'quarantine',
            'records'          => $records,
            'totalCount'       => $totalCount,
            'statusCounts'     => $statusCounts,
            'reclaimableSize'  => $reclaimableSize,
            'currentPage'      => $page,
            'totalPages'       => $totalPages,
            'perPage'          => $perPage,
            'rangeFrom'        => $totalCount > 0 ? $offset + 1 : 0,
            'rangeTo'          => min($offset + $perPage, $totalCount),
            'prevPageUrl'      => $page > 1
                ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $page - 1]))
                : null,
            'nextPageUrl'      => $page < $totalPages
                ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $page + 1]))
                : null,
            'firstPageUrl'     => $page > 1
                ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => 1]))
                : null,
            'lastPageUrl'      => $page < $totalPages
                ? (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $totalPages]))
                : null,
            'pageNumbers'      => $this->buildPageNumbers($page, $totalPages, $baseParams),
            'perPageOptions'   => $perPageOptions,
            'currentFilters'   => [
                'status'  => $statusFilter,
                'reason'  => $reasonFilter,
                'scanId'  => $scanIdFilter,
                'level'   => $levelFilter,
                'folder'  => $folderFilter,
            ],
            'folderId'          => $folderId,
            'folderName'        => $folderName,
            'availableStorages' => $this->quarantineRepository->getAvailableStorageUids(),
            'availableScanIds'  => $this->quarantineRepository->getAvailableScanIds(),
            'availableReasons'  => [
                QuarantineRepository::REASON_UNUSED,
                QuarantineRepository::REASON_MISSING,
                QuarantineRepository::REASON_ORPHAN,
            ],
            'availableStatuses' => [
                QuarantineRepository::STATUS_CANDIDATE,
                QuarantineRepository::STATUS_QUARANTINED,
                QuarantineRepository::STATUS_SKIPPED,
                QuarantineRepository::STATUS_RESTORED,
                QuarantineRepository::STATUS_FLUSHED,
                QuarantineRepository::STATUS_FAILED,
            ],
            'actionUrl'     => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            'filterFormUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
            'moduleUrl'     => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE),
        ]);

        return $moduleTemplate->renderResponse('Quarantine/Index');
    }

    private function handleBulkAction(ServerRequestInterface $request): ResponseInterface
    {
        $body   = $request->getParsedBody();
        $action = (string)($body['bulk_action'] ?? '');
        if (!empty($body['clear_type'])) {
            $action = 'clear_queue';
        }
        $uids   = array_values(array_filter(array_map('intval', (array)($body['record_uids'] ?? []))));

        $redirectParams = array_filter((array)($body['redirect_params'] ?? []), static fn($v) => $v !== '' && $v !== null && $v !== '0');

        // ── Actions that do not require selected records ──────────────────────
        if ($action === 'scan_folder') {
            return $this->handleScan($body, $redirectParams);
        }
        if ($action === 'clear_queue') {
            return $this->handleClearQueue($body, $redirectParams);
        }

        if (empty($uids)) {
            $this->flash('No records selected.', 'No selection', ContextualFeedbackSeverity::WARNING);
            return $this->redirect($redirectParams);
        }

        $records      = $this->quarantineRepository->findByUids($uids);
        $successCount = 0;
        $skipCount    = 0;
        $failCount    = 0;

        foreach ($records as $record) {
            $uid             = (int)$record['uid'];
            $storageBasePath = $this->fileCleanupService->getStorageBasePath((int)$record['storage_uid']);

            switch ($action) {
                case 'recheck':
                    $result = $this->validationService->validate($record, $storageBasePath);
                    if ($result['valid']) {
                        $this->quarantineRepository->updateRecord($uid, [
                            'last_checked_at' => time(),
                            'updated_at'      => time(),
                            'error_message'   => null,
                        ]);
                        $successCount++;
                    } else {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'          => $result['skip'] ? QuarantineRepository::STATUS_SKIPPED : QuarantineRepository::STATUS_FAILED,
                            'error_message'   => $result['error'],
                            'last_checked_at' => time(),
                            'updated_at'      => time(),
                        ]);
                        $skipCount++;
                    }
                    break;

                case 'quarantine':
                    if (in_array($record['status'], [QuarantineRepository::STATUS_QUARANTINED, QuarantineRepository::STATUS_FLUSHED], true)) {
                        $skipCount++;
                        break;
                    }
                    $validation = $this->validationService->validate($record, $storageBasePath);
                    if (!$validation['valid']) {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'          => $validation['skip'] ? QuarantineRepository::STATUS_SKIPPED : QuarantineRepository::STATUS_FAILED,
                            'error_message'   => $validation['error'],
                            'updated_at'      => time(),
                            'last_checked_at' => time(),
                        ]);
                        $skipCount++;
                        break;
                    }
                    $result = $this->flushService->moveToQuarantine($record, $storageBasePath);
                    if ($result['success']) {
                        $this->actionLogger->log(ActionLogger::ACTION_QUARANTINE, (string)$record['identifier'], (string)$record['name'], (int)$record['storage_uid'], (int)$record['file_uid']);
                        $successCount++;
                    } else {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'        => QuarantineRepository::STATUS_FAILED,
                            'error_message' => $result['error'],
                            'updated_at'    => time(),
                        ]);
                        $failCount++;
                    }
                    break;

                case 'restore':
                    if ($record['status'] !== QuarantineRepository::STATUS_QUARANTINED) {
                        $skipCount++;
                        break;
                    }
                    $result = $this->restoreService->restore($record, $storageBasePath);
                    if ($result['success']) {
                        $this->actionLogger->log(ActionLogger::ACTION_RESTORE, (string)$record['identifier'], (string)$record['name'], (int)$record['storage_uid'], (int)$record['file_uid']);
                        $successCount++;
                    } else {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'        => QuarantineRepository::STATUS_FAILED,
                            'error_message' => $result['error'],
                            'updated_at'    => time(),
                        ]);
                        $failCount++;
                    }
                    break;

                case 'delete':
                    if ($record['status'] !== QuarantineRepository::STATUS_QUARANTINED) {
                        $skipCount++;
                        break;
                    }
                    $result = $this->flushService->flushQuarantined($record, $storageBasePath);
                    if ($result['success']) {
                        $this->actionLogger->log(ActionLogger::ACTION_FLUSH, (string)$record['identifier'], (string)$record['name'], (int)$record['storage_uid'], (int)$record['file_uid']);
                        $successCount++;
                    } else {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'        => QuarantineRepository::STATUS_FAILED,
                            'error_message' => $result['error'],
                            'updated_at'    => time(),
                        ]);
                        $failCount++;
                    }
                    break;

                case 'skip':
                    $this->quarantineRepository->updateRecord($uid, [
                        'status'        => QuarantineRepository::STATUS_SKIPPED,
                        'error_message' => 'Manually skipped by editor',
                        'updated_at'    => time(),
                    ]);
                    $this->actionLogger->log(ActionLogger::ACTION_SKIP, (string)$record['identifier'], (string)$record['name'], (int)$record['storage_uid'], (int)$record['file_uid']);
                    $successCount++;
                    break;

                default:
                    $failCount++;
            }
        }

        $summary  = ucfirst($action) . ': ' . $successCount . ' succeeded';
        $summary .= $skipCount > 0 ? ", {$skipCount} skipped" : '';
        $summary .= $failCount  > 0 ? ", {$failCount} failed" : '';
        $severity = $failCount > 0 ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::OK;
        $this->flash($summary, ucfirst($action), $severity);

        return $this->redirect($redirectParams);
    }

    private function handleScan(array $body, array $redirectParams): ResponseInterface
    {
        $storageUid      = max(1, (int)($body['scan_storage_uid'] ?? 1));
        $folderPrefix    = trim((string)($body['scan_folder'] ?? ''));
        $olderThanDays   = max(0, (int)($body['scan_older_than'] ?? 90));
        $level           = (int)($body['scan_level'] ?? 0);
        $level           = in_array($level, [1, 2, 3], true) ? $level : 0;
        $includeUnused   = !empty($body['scan_unused']);
        $includeMissing  = !empty($body['scan_missing']);
        $includeOrphans  = !empty($body['scan_orphans']);

        if (!$includeUnused && !$includeMissing && !$includeOrphans) {
            $this->flash('Select at least one scan type.', 'Scan', ContextualFeedbackSeverity::WARNING);
            return $this->redirect($redirectParams);
        }

        $scanId      = $this->scanService->generateScanId();
        // 0 = no age limit; any positive value would otherwise always evaluate to a
        // real (non-zero) timestamp, so the "no filter" case needs an explicit branch.
        $olderThan   = $olderThanDays > 0 ? time() - ($olderThanDays * 86400) : 0;
        $total       = 0;

        if ($includeUnused) {
            $stats  = $this->scanService->scanUnusedFiles($storageUid, $folderPrefix, $olderThan, 5000, $scanId, $level);
            $total += $stats['new'] ?? 0;
        }
        if ($includeMissing) {
            $stats  = $this->scanService->scanMissingFiles($storageUid, $scanId, $folderPrefix, $level);
            $total += $stats['new'] ?? 0;
        }
        if ($includeOrphans) {
            $stats  = $this->scanService->scanPhysicalOrphans($storageUid, $folderPrefix, 5000, $scanId, $level);
            $total += $stats['new'] ?? 0;
        }

        $this->flash(
            "{$total} candidate(s) queued (scan ID: {$scanId}).",
            'Scan complete',
            $total > 0 ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::INFO
        );

        // Land on this scan's own results, not whatever scan_id filter was active before —
        // otherwise the list still shows the entire accumulated queue from past scans.
        $redirectParams['scan_id'] = $scanId;

        return $this->redirect($redirectParams);
    }

    private function handleClearQueue(array $body, array $redirectParams): ResponseInterface
    {
        $clearType = (string)($body['clear_type'] ?? 'resolved');
        switch ($clearType) {
            case 'candidates':
                $statuses = [QuarantineRepository::STATUS_CANDIDATE];
                $label    = 'candidates';
                break;
            case 'all':
                $statuses = [
                    QuarantineRepository::STATUS_CANDIDATE,
                    QuarantineRepository::STATUS_QUARANTINED,
                    QuarantineRepository::STATUS_SKIPPED,
                    QuarantineRepository::STATUS_RESTORED,
                    QuarantineRepository::STATUS_FLUSHED,
                    QuarantineRepository::STATUS_FAILED,
                ];
                $label = 'all records';
                break;
            default: // 'resolved'
                $statuses = [
                    QuarantineRepository::STATUS_FLUSHED,
                    QuarantineRepository::STATUS_RESTORED,
                    QuarantineRepository::STATUS_SKIPPED,
                    QuarantineRepository::STATUS_FAILED,
                ];
                $label = 'resolved records';
        }

        try {
            $deleted = $clearType === 'all'
                ? $this->quarantineRepository->deleteAll()
                : $this->quarantineRepository->deleteByStatuses($statuses);
            $this->flash("{$deleted} {$label} removed from queue.", 'Queue cleared', ContextualFeedbackSeverity::OK);
        } catch (\Throwable $e) {
            $this->flash('Delete failed: ' . $e->getMessage(), 'Queue clear error', ContextualFeedbackSeverity::ERROR);
        }
        return $this->redirect($redirectParams);
    }

    private function flash(string $message, string $title, ContextualFeedbackSeverity $severity): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()
            ->enqueue(new FlashMessage($message, $title, $severity, true));
    }

    private function redirect(array $params): RedirectResponse
    {
        return new RedirectResponse(
            (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $params)
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

    /**
     * Windowed page-number list for pagination: first page, last page, and a run of pages
     * around the current one, with gaps collapsed to an ellipsis marker.
     *
     * @return array<int, array{type: string, page?: int, url?: string, active?: bool}>
     */
    private function buildPageNumbers(int $currentPage, int $totalPages, array $baseParams): array
    {
        if ($totalPages <= 1) {
            return [];
        }

        $window = 2;
        $pages  = array_unique(array_filter(
            array_merge(
                [1, $totalPages],
                range(max(1, $currentPage - $window), min($totalPages, $currentPage + $window))
            ),
            static fn(int $p): bool => $p >= 1 && $p <= $totalPages
        ));
        sort($pages);

        $items    = [];
        $previous = null;
        foreach ($pages as $p) {
            if ($previous !== null && $p - $previous > 1) {
                $items[] = ['type' => 'ellipsis'];
            }
            $items[] = [
                'type'   => 'page',
                'page'   => $p,
                'url'    => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, array_merge($baseParams, ['page' => $p])),
                'active' => $p === $currentPage,
            ];
            $previous = $p;
        }

        return $items;
    }

    /**
     * How many folder segments a file sits below $folderPrefix.
     * A file directly inside the folder is depth 1; one subfolder down is depth 2, etc.
     */
    private function folderDepth(string $identifier, string $folderPrefix): int
    {
        $relative = str_starts_with($identifier, $folderPrefix)
            ? substr($identifier, strlen($folderPrefix))
            : $identifier;
        return substr_count(trim($relative, '/'), '/') + 1;
    }
}
