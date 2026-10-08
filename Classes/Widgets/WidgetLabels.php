<?php

declare(strict_types=1);

namespace Anubit\Filefix\Widgets;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Translated labels for the dashboard widgets (locallang_dashboard.xlf, German in de.locallang_dashboard.xlf).
 * Falls back to $default when no backend LanguageService is available (e.g. CLI).
 */
final class WidgetLabels
{
    private const LL = 'LLL:EXT:filefix/Resources/Private/Language/locallang_dashboard.xlf:';

    public static function get(string $key, string $default, string ...$arguments): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = $languageService instanceof LanguageService ? $languageService->sL(self::LL . $key) : '';
        $label = $label !== '' ? $label : $default;
        return $arguments === [] ? $label : sprintf($label, ...$arguments);
    }

    public static function decimalSeparator(): string
    {
        return self::get('format.decimalSeparator', '.');
    }
}
