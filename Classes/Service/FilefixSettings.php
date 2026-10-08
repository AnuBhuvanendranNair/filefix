<?php

declare(strict_types=1);

namespace Anubit\Filefix\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension settings (ext_conf_template.txt, Admin Tools > Settings > Extension Configuration).
 * Values are validated and fall back to the defaults: in composer installations the settings
 * are only written to settings.php once they are saved, ExtensionConfiguration::get() throws before.
 */
class FilefixSettings
{
    private const DEFAULTS = [
        'imageMaxWidth'  => 1900,
        'imageMaxHeight' => 1900,
    ];
    /** Raster formats checked by the oversized report (no svg/eps; gif left out, it may be animated) */
    private const REPORT_FILE_TYPES = ['jpg', 'jpeg', 'jfif', 'png', 'webp', 'tif', 'tiff', 'bmp'];
    /** Formats that may be resized in place (format kept); all other report types stay report-only */
    private const RESIZE_FILE_TYPES = ['jpg', 'jpeg', 'png'];

    private ?array $settings = null;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getImageMaxWidth(): int
    {
        return $this->getInt('imageMaxWidth', 100, 10000);
    }

    public function getImageMaxHeight(): int
    {
        return $this->getInt('imageMaxHeight', 100, 10000);
    }

    /** @return string[] lowercase raster formats checked by the oversized report (fixed) */
    public function getImageFileTypes(): array
    {
        return self::REPORT_FILE_TYPES;
    }

    /** @return string[] lowercase formats that may be resized, format is kept (fixed) */
    public function getImageResizeTypes(): array
    {
        return self::RESIZE_FILE_TYPES;
    }

    private function getInt(string $key, int $min, int $max): int
    {
        $value = (int)$this->get($key);
        return $value >= $min && $value <= $max ? $value : (int)self::DEFAULTS[$key];
    }

    private function get(string $key): mixed
    {
        if ($this->settings === null) {
            try {
                $this->settings = (array)$this->extensionConfiguration->get('filefix');
            } catch (\Throwable) {
                $this->settings = [];
            }
        }
        $value = $this->settings[$key] ?? null;
        return $value === null || $value === '' ? self::DEFAULTS[$key] : $value;
    }
}
