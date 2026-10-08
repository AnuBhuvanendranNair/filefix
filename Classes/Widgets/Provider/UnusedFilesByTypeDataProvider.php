<?php

declare(strict_types=1);

namespace Anubit\Filefix\Widgets\Provider;

use Anubit\Filefix\Service\FileStatisticsService;
use Anubit\Filefix\Widgets\WidgetLabels;
use TYPO3\CMS\Dashboard\WidgetApi;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Doughnut chart data: unused files per file type, either by number of files
 * ($metric = 'count') or by size in bytes ($metric = 'bytes'). The biggest types
 * get their own slice, the rest is summed up as "other". The number of slices
 * follows the core chart palette so every slice gets its own color.
 */
class UnusedFilesByTypeDataProvider implements ChartDataProviderInterface
{
    public function __construct(
        private readonly FileStatisticsService $statisticsService,
        private readonly string $metric = 'count',
    ) {}

    public function getChartData(): array
    {
        $values = array_map(
            fn(array $stat): int => (int)($stat[$this->metric] ?? 0),
            $this->statisticsService->getSnapshot()['byExtension']
        );
        arsort($values);

        $colors    = WidgetApi::getDefaultChartColors();
        $maxSlices = count($values) > count($colors) ? count($colors) - 1 : count($colors);
        $slices    = array_slice($values, 0, $maxSlices, true);
        $other     = array_sum(array_slice($values, $maxSlices, null, true));
        if ($other > 0) {
            $slices[WidgetLabels::get('chart.other', 'other')] = $other;
        }

        $labels = array_map(fn($ext): string => $ext === '' ? WidgetLabels::get('chart.noExtension', '(none)') : (string)$ext, array_keys($slices));
        $data   = array_values($slices);

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => $this->metric === 'bytes' ? 'Size' : 'Files',
                    'backgroundColor' => array_slice($colors, 0, count($data)),
                    'data'            => $data,
                ],
            ],
        ];
    }
}
