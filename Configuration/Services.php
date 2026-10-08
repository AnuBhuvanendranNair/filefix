<?php

declare(strict_types=1);

namespace Anubit\Filefix;

use Anubit\Filefix\Service\FileStatisticsService;
use Anubit\Filefix\Widgets\Provider\UnusedFilesByTypeDataProvider;
use Anubit\Filefix\Widgets\UnusedFilesChartWidget;
use Anubit\Filefix\Widgets\UnusedFilesNumberWidget;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;

return function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder) {
    /**
     * Dashboard widgets are only registered when EXT:dashboard is installed (WidgetRegistry defined),
     * same approach as EXT:seo. Classes/Widgets/ is excluded from autoloading in Services.yaml for that reason.
     * Arguments are passed by name: v12 widgets have an extra deprecated $view argument at position 4.
     */
    if (!$containerBuilder->hasDefinition(WidgetRegistry::class)) {
        return;
    }

    $services = $configurator->services();
    $ll = 'LLL:EXT:filefix/Resources/Private/Language/locallang_dashboard.xlf:';

    $providers = [
        'filefix.dashboard.provider.unusedByTypeCount' => [UnusedFilesByTypeDataProvider::class, 'count'],
        'filefix.dashboard.provider.unusedByTypeSize'  => [UnusedFilesByTypeDataProvider::class, 'bytes'],
    ];
    foreach ($providers as $id => [$class, $metric]) {
        $services->set($id)
            ->class($class)
            ->arg('$statisticsService', new Reference(FileStatisticsService::class))
            ->arg('$metric', $metric);
    }

    $charts = [
        'unusedFilesByType' => ['filefix.dashboard.provider.unusedByTypeCount', 'count'],
        'unusedSpaceByType' => ['filefix.dashboard.provider.unusedByTypeSize', 'bytes'],
    ];
    foreach ($charts as $identifier => [$providerId, $metric]) {
        $services->set('dashboard.widget.filefix.' . $identifier)
            ->class(UnusedFilesChartWidget::class)
            ->arg('$dataProvider', new Reference($providerId))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$metric', $metric)
            ->arg('$options', ['refreshAvailable' => true])
            ->tag('dashboard.widget', [
                'identifier'     => 'filefix-' . $identifier,
                'groupNames'     => 'filefix',
                'title'          => $ll . 'widget.' . $identifier . '.title',
                'description'    => $ll . 'widget.' . $identifier . '.description',
                'iconIdentifier' => 'content-widget-chart-pie',
                'height'         => 'medium',
                'width'          => 'small',
            ]);
    }

    $numbers = [
        'unusedFilesCount' => ['count', 'actions-file'],
        'reclaimableSpace' => ['bytes', 'actions-database'],
    ];
    foreach ($numbers as $identifier => [$metric, $icon]) {
        $services->set('dashboard.widget.filefix.' . $identifier)
            ->class(UnusedFilesNumberWidget::class)
            ->arg('$statisticsService', new Reference(FileStatisticsService::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$metric', $metric)
            ->arg('$options', [
                'text'             => $ll . 'widget.' . $identifier . '.text',
                'icon'             => $icon,
                'refreshAvailable' => true,
            ])
            ->tag('dashboard.widget', [
                'identifier'     => 'filefix-' . $identifier,
                'groupNames'     => 'filefix',
                'title'          => $ll . 'widget.' . $identifier . '.title',
                'description'    => $ll . 'widget.' . $identifier . '.description',
                'iconIdentifier' => 'content-widget-number',
                'height'         => 'small',
                'width'          => 'small',
            ]);
    }
};
