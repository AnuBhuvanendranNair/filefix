<?php

declare(strict_types=1);

namespace Anubit\Filefix\Widgets;

use Anubit\Filefix\Service\FileStatisticsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\EventDataInterface;
use TYPO3\CMS\Dashboard\Widgets\JavaScriptInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * Doughnut chart with total on top, legend at the bottom and tooltip "jpg (4.5 GB)".
 *
 * Own widget instead of core DoughnutChartWidget: the tooltip format needs the Chart.js plugin
 * from @anubit/filefix/dashboard-doughnut-tooltip.js, which core does not load.
 * Rendering itself stays with core (Widget/ChartWidget template + chart-initializer), same in v12, v13, v14.
 */
class UnusedFilesChartWidget implements WidgetInterface, RequestAwareWidgetInterface, EventDataInterface, JavaScriptInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly ChartDataProviderInterface $dataProvider,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly string $metric = 'count',
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['typo3/cms-dashboard']);
        $view->assignMultiple([
            'button'        => null,
            'options'       => $this->options,
            'configuration' => $this->configuration,
        ]);
        return $view->render('Widget/ChartWidget');
    }

    public function getEventData(): array
    {
        $data  = $this->dataProvider->getChartData();
        $total = (int)array_sum($data['datasets'][0]['data'] ?? []);
        if (isset($data['datasets'][0])) {
            // Hovered slice moves outwards
            $data['datasets'][0]['hoverOffset'] = 24;
        }

        return [
            'graphConfig' => [
                'type'    => 'doughnut',
                'options' => [
                    'maintainAspectRatio' => false,
                    'cutout'              => '55%',
                    'layout'  => ['padding' => 8],
                    'plugins' => [
                        'legend'  => [
                            'display'  => true,
                            'position' => 'bottom',
                            'labels'   => ['boxWidth' => 12, 'padding' => 10],
                        ],
                        'title'  => [
                            'display' => true,
                            'text'    => WidgetLabels::get('chart.total', 'Total: %s', $this->metric === 'bytes'
                                ? FileStatisticsService::formatBytes($total, WidgetLabels::decimalSeparator())
                                : WidgetLabels::get('chart.files', '%s files', (string)$total)),
                            'font'    => ['size' => 14, 'weight' => 'bold'],
                            'padding' => ['top' => 4, 'bottom' => 10],
                        ],
                        'filefixTooltip' => [
                            'unit'             => $this->metric === 'bytes' ? 'bytes' : 'files',
                            'filesLabel'       => WidgetLabels::get('chart.files', '%s files'),
                            'decimalSeparator' => WidgetLabels::decimalSeparator(),
                        ],
                    ],
                ],
                'data' => $data,
            ],
        ];
    }

    public function getJavaScriptModuleInstructions(): array
    {
        return [
            JavaScriptModuleInstruction::create('@typo3/dashboard/contrib/chartjs.js'),
            JavaScriptModuleInstruction::create('@anubit/filefix/dashboard-doughnut-tooltip.js'),
            JavaScriptModuleInstruction::create('@typo3/dashboard/chart-initializer.js'),
        ];
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
