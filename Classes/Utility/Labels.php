<?php

declare(strict_types=1);

namespace Anubit\Filefix\Utility;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translated labels from EXT:filefix/Resources/Private/Language/locallang.xlf (German in de.locallang.xlf).
 * Uses the backend user's language; English when no backend language service exists (CLI).
 */
final class Labels
{
    private const FILE = 'LLL:EXT:filefix/Resources/Private/Language/locallang.xlf:';

    public static function get(string $key, string|int|float ...$arguments): string
    {
        $label = self::getLanguageService()->sL(self::FILE . $key);
        $label = $label !== '' ? $label : $key;
        return $arguments === [] ? $label : vsprintf($label, $arguments);
    }

    /**
     * Several labels at once, e.g. for JavaScript (passed as JSON from the template).
     *
     * @param string[] $keys
     * @return array<string, string>
     */
    public static function many(array $keys): array
    {
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = self::get($key);
        }
        return $labels;
    }

    private static function getLanguageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        return $languageService instanceof LanguageService
            ? $languageService
            : GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }
}
