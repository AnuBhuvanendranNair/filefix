<?php

declare(strict_types=1);

namespace Anubit\Filefix\Command;

use Anubit\Filefix\Repository\QuarantineRepository;
use Anubit\Filefix\Service\FileCleanupService;
use Anubit\Filefix\Service\QuarantineFlushService;
use Anubit\Filefix\Service\QuarantineValidationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FlushCleanupCandidatesCommand extends Command
{
    public function __construct(
        private readonly QuarantineRepository $quarantineRepository,
        private readonly QuarantineFlushService $flushService,
        private readonly QuarantineValidationService $validationService,
        private readonly FileCleanupService $fileCleanupService,
    ) {
        parent::__construct('filefix:cleanup:flush');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Process queued cleanup candidates: move to quarantine or permanently delete quarantined files')
            ->addOption('storage',            null, InputOption::VALUE_REQUIRED, 'Storage UID', '1')
            ->addOption('status',             null, InputOption::VALUE_REQUIRED, 'Process records with this status (candidate or quarantined)', 'candidate')
            ->addOption('older-than',         null, InputOption::VALUE_REQUIRED, 'Only process records queued longer than (e.g. 14d)', '')
            ->addOption('limit',              null, InputOption::VALUE_REQUIRED, 'Max records to process per run', '1000')
            ->addOption('move-to-quarantine', null, InputOption::VALUE_NONE,     'Move candidate files to the quarantine folder (candidate → quarantined)')
            ->addOption('delete',             null, InputOption::VALUE_NONE,     'Permanently delete quarantined files and FAL records (quarantined → flushed)')
            ->addOption('scan-id',            null, InputOption::VALUE_REQUIRED, 'Only process records from a specific scan ID', '')
            ->addOption('force',              null, InputOption::VALUE_NONE,     'Allow direct candidate → flushed, bypassing quarantine step')
            ->addOption('dry-run',            null, InputOption::VALUE_NONE,     'Simulate processing without making any changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storageUid       = (int)$input->getOption('storage');
        $statusFilter     = (string)$input->getOption('status');
        $olderThanStr     = (string)$input->getOption('older-than');
        $limit            = max(1, (int)$input->getOption('limit'));
        $moveToQuarantine = (bool)$input->getOption('move-to-quarantine');
        $delete           = (bool)$input->getOption('delete');
        $scanId           = (string)$input->getOption('scan-id');
        $force            = (bool)$input->getOption('force');
        $dryRun           = (bool)$input->getOption('dry-run');

        if (!$moveToQuarantine && !$delete) {
            $io->error('Specify at least one action: --move-to-quarantine or --delete');
            return Command::FAILURE;
        }

        if ($delete && $statusFilter === QuarantineRepository::STATUS_CANDIDATE && !$force) {
            $io->error(
                'Direct deletion from candidate status requires --force.' . PHP_EOL
                . 'Preferred lifecycle: candidate → quarantined → flushed.' . PHP_EOL
                . 'Use --move-to-quarantine first, then run --delete --status=quarantined.'
            );
            return Command::FAILURE;
        }

        $storageBasePath = $this->fileCleanupService->getStorageBasePath($storageUid);

        $filters = ['storage_uid' => $storageUid, 'status' => $statusFilter];
        if ($scanId !== '') {
            $filters['scan_id'] = $scanId;
        }
        if ($olderThanStr !== '') {
            $filters['queued_before'] = $this->parseDuration($olderThanStr);
        }

        $records = $this->quarantineRepository->findForFlush($filters, $limit);

        $io->title('GC Mimefix — Cleanup Flush' . ($dryRun ? ' [DRY RUN]' : ''));
        $io->text(sprintf(
            'Processing %d record(s). Storage: %d, Status: %s%s',
            count($records),
            $storageUid,
            $statusFilter,
            $dryRun ? ' [DRY RUN — no changes]' : ''
        ));
        $io->newLine();

        $moved   = 0;
        $deleted = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($records as $record) {
            $uid = (int)$record['uid'];

            // Determine action based on status + flags
            $action = null;
            if ($moveToQuarantine && $record['status'] === QuarantineRepository::STATUS_CANDIDATE) {
                $action = 'quarantine';
            } elseif ($delete && $record['status'] === QuarantineRepository::STATUS_QUARANTINED) {
                $action = 'delete';
            } elseif ($delete && $force && $record['status'] === QuarantineRepository::STATUS_CANDIDATE) {
                $action = 'delete_direct';
            }

            if ($action === null) {
                continue;
            }

            // Safety re-check
            $validation = $this->validationService->validate($record, $storageBasePath);

            if (!$validation['valid']) {
                $msg = "uid={$uid}: {$validation['error']}";
                if ($dryRun) {
                    $io->warning("Would skip {$msg}");
                } else {
                    $newStatus = $validation['skip']
                        ? QuarantineRepository::STATUS_SKIPPED
                        : QuarantineRepository::STATUS_FAILED;
                    $this->quarantineRepository->updateRecord($uid, [
                        'status'          => $newStatus,
                        'error_message'   => $validation['error'],
                        'updated_at'      => time(),
                        'last_checked_at' => time(),
                    ]);
                    $io->warning("Skipped {$msg}");
                }
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $io->text("Would {$action}: uid={$uid} {$record['identifier']}");
                $action === 'delete' || $action === 'delete_direct' ? $deleted++ : $moved++;
                continue;
            }

            if ($action === 'quarantine') {
                $result = $this->flushService->moveToQuarantine($record, $storageBasePath);
                if ($result['success']) {
                    $io->text("Quarantined uid={$uid}: {$record['identifier']} → {$result['quarantine_path']}");
                    $moved++;
                } else {
                    $this->quarantineRepository->updateRecord($uid, [
                        'status'          => QuarantineRepository::STATUS_FAILED,
                        'error_message'   => $result['error'],
                        'updated_at'      => time(),
                        'last_checked_at' => time(),
                    ]);
                    $io->error("Failed uid={$uid}: {$result['error']}");
                    $failed++;
                }
            } else {
                // delete or delete_direct
                if ($action === 'delete_direct') {
                    // Move to quarantine first, then immediately flush
                    $moveResult = $this->flushService->moveToQuarantine($record, $storageBasePath);
                    if (!$moveResult['success']) {
                        $this->quarantineRepository->updateRecord($uid, [
                            'status'          => QuarantineRepository::STATUS_FAILED,
                            'error_message'   => $moveResult['error'],
                            'updated_at'      => time(),
                        ]);
                        $io->error("Failed uid={$uid} (quarantine step): {$moveResult['error']}");
                        $failed++;
                        continue;
                    }
                    // Reload record to get updated quarantine_identifier
                    $record = $this->quarantineRepository->findById($uid) ?? $record;
                }

                $result = $this->flushService->flushQuarantined($record, $storageBasePath);
                if ($result['success']) {
                    $io->text("Flushed uid={$uid}: {$record['identifier']}");
                    $deleted++;
                } else {
                    $this->quarantineRepository->updateRecord($uid, [
                        'status'          => QuarantineRepository::STATUS_FAILED,
                        'error_message'   => $result['error'],
                        'updated_at'      => time(),
                        'last_checked_at' => time(),
                    ]);
                    $io->error("Failed uid={$uid}: {$result['error']}");
                    $failed++;
                }
            }
        }

        $io->newLine();
        $io->success(sprintf(
            'Done. Moved to quarantine: %d, Permanently deleted: %d, Skipped/invalid: %d, Failed: %d',
            $moved, $deleted, $skipped, $failed
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function parseDuration(string $value): int
    {
        $now = time();
        if (preg_match('/^(\d+)d$/i', $value, $m)) {
            return $now - (int)$m[1] * 86400;
        }
        if (preg_match('/^(\d+)w$/i', $value, $m)) {
            return $now - (int)$m[1] * 7 * 86400;
        }
        if (preg_match('/^(\d+)h$/i', $value, $m)) {
            return $now - (int)$m[1] * 3600;
        }
        return $now - 14 * 86400;
    }
}
