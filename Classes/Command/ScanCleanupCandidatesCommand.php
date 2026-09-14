<?php

declare(strict_types=1);

namespace Anubit\Filefix\Command;

use Anubit\Filefix\Service\QuarantineScanService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ScanCleanupCandidatesCommand extends Command
{
    public function __construct(
        private readonly QuarantineScanService $scanService,
    ) {
        parent::__construct('filefix:cleanup:scan');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Scan file storages for cleanup candidates and queue them in tx_filefix_quarantine')
            ->addOption('storage',                  null, InputOption::VALUE_REQUIRED, 'Storage UID to scan', '1')
            ->addOption('folder',                   null, InputOption::VALUE_REQUIRED, 'FAL folder identifier to restrict scan (e.g. /images/)', '')
            ->addOption('older-than',               null, InputOption::VALUE_REQUIRED, 'Only files older than this (e.g. 90d, 2w, 24h)', '90d')
            ->addOption('limit',                    null, InputOption::VALUE_REQUIRED, 'Max candidates per scan type', '5000')
            ->addOption('include-unused',           null, InputOption::VALUE_NONE,     'Scan for FAL files with no references')
            ->addOption('include-missing',          null, InputOption::VALUE_NONE,     'Scan for FAL records where physical file is missing')
            ->addOption('include-physical-orphans', null, InputOption::VALUE_NONE,     'Scan for physical files not indexed in sys_file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storageUid     = (int)$input->getOption('storage');
        $folderPrefix   = (string)$input->getOption('folder');
        $olderThanStr   = (string)$input->getOption('older-than');
        $limit          = max(1, (int)$input->getOption('limit'));
        $includeUnused  = (bool)$input->getOption('include-unused');
        $includeMissing = (bool)$input->getOption('include-missing');
        $includeOrphans = (bool)$input->getOption('include-physical-orphans');

        if (!$includeUnused && !$includeMissing && !$includeOrphans) {
            $io->error('Specify at least one of: --include-unused, --include-missing, --include-physical-orphans');
            return Command::FAILURE;
        }

        $olderThanTimestamp = self::parseDuration($olderThanStr);
        $scanId             = $this->scanService->generateScanId();

        $io->title('GC Mimefix — Cleanup Scan');
        $io->definitionList(
            ['Storage UID' => $storageUid],
            ['Folder'      => $folderPrefix ?: '(all)'],
            ['Older than'  => $olderThanStr . ' (before ' . date('Y-m-d H:i:s', $olderThanTimestamp) . ')'],
            ['Limit'       => $limit],
            ['Scan ID'     => $scanId]
        );

        $total = 0;

        if ($includeUnused) {
            $io->section('Scanning for unused FAL files...');
            $stats = $this->scanService->scanUnusedFiles($storageUid, $folderPrefix, $olderThanTimestamp, $limit, $scanId);
            $io->success("Queued {$stats['new']} unused file candidate(s).");
            $total += $stats['new'];
        }

        if ($includeMissing) {
            $io->section('Scanning for missing physical files...');
            $stats = $this->scanService->scanMissingFiles($storageUid, $scanId, $folderPrefix);
            $io->success("Queued {$stats['new']} missing-file candidate(s).");
            $total += $stats['new'];
        }

        if ($includeOrphans) {
            $io->section('Scanning for physical orphans...');
            $stats = $this->scanService->scanPhysicalOrphans($storageUid, $folderPrefix, $limit, $scanId);
            $io->success("Queued {$stats['new']} physical orphan candidate(s).");
            $total += $stats['new'];
        }

        $io->success("Scan complete. Total candidates queued/updated: {$total}. Scan ID: {$scanId}");
        return Command::SUCCESS;
    }

    /**
     * Parse a human duration string (e.g. 90d, 2w, 24h) into a Unix timestamp threshold.
     * Returns: now - duration (i.e. files older than this timestamp qualify).
     */
    public static function parseDuration(string $value): int
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
        // Default fallback: 90 days
        return $now - 90 * 86400;
    }
}
