<?php

declare(strict_types=1);

namespace Anubit\Filefix\Command;

use Anubit\Filefix\Service\FileStatisticsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdateFileStatisticsCommand extends Command
{
    public function __construct(
        private readonly FileStatisticsService $statisticsService,
    ) {
        parent::__construct('filefix:stats:update');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rebuild the unused-file statistics shown in the filefix dashboard widgets')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Storage UID (default: default storage)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storageOption = (string)$input->getOption('storage');
        $snapshot = $this->statisticsService->refresh($storageOption !== '' ? (int)$storageOption : null);

        $io->definitionList(
            ['Storage UID'  => $snapshot['storageUid']],
            ['Unused files (on disk)' => $snapshot['unusedCount']],
            ['Reclaimable size' => round($snapshot['unusedBytes'] / 1048576, 1) . ' MB'],
            ['File types'   => count($snapshot['byExtension'])],
            ['Left out: not on disk'             => $snapshot['notOnDisk']],
            ['Left out: used via another record' => $snapshot['usedViaOtherRecord']]
        );
        $io->success('Statistics updated.');

        return Command::SUCCESS;
    }
}
