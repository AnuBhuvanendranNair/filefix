<?php

declare(strict_types=1);

namespace Anubit\Filefix\Widgets;

use Anubit\Filefix\Service\FileStatisticsService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * Number widget: unused files ($metric = 'count') or reclaimable space ($metric = 'bytes').
 *
 * Own widget instead of core NumberWithIconWidget: core renders title, subtitle, number in that
 * order and only accepts an int, so "4867 files without any reference" and "6.4 GB" are not possible.
 * Markup and CSS classes follow the core number widget (identical in v12, v13, v14).
 */
class UnusedFilesNumberWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly FileStatisticsService $statisticsService,
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
        $snapshot = $this->statisticsService->getSnapshot();
        $view = $this->backendViewFactory->create($this->request, ['typo3/cms-dashboard', 'anubit/filefix']);
        $view->assignMultiple([
            'icon'          => $this->options['icon'] ?? '',
            'number'        => $this->metric === 'bytes'
                ? FileStatisticsService::formatBytes($snapshot['unusedBytes'], WidgetLabels::decimalSeparator())
                : (string)$snapshot['unusedCount'],
            'text'          => $this->options['text'] ?? '',
            'options'       => $this->options,
            'configuration' => $this->configuration,
        ]);
        return $view->render('Widget/UnusedFilesNumberWidget');
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
