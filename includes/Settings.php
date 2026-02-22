<?php
/**
 * Settings Manager
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Manages plugin settings
 */
final class Settings
{
    /**
     * Option name in database
     */
    public const OPTION_NAME = 'rapls_pic_settings';

    /**
     * Default settings
     */
    private const DEFAULTS = [
        'max_width' => 1024,
        'max_height' => 1024,
        'quality' => 90,
        'format' => 'jpeg',
        'bgcolor' => 'white',
        'page' => 0,
        'auto_generate' => true,
        'set_featured' => true,
        // Insert settings
        'insert_size' => 'medium',
        'insert_type' => 'image',
        'insert_link' => 'file',
        'custom_html' => '',
        // Display settings
        'display_thumbnail_icon' => true,
        'hide_generated_images' => true,
        'keep_on_uninstall' => false,
    ];

    /**
     * Cached settings
     */
    private ?array $settings = null;

    /**
     * Get all settings
     *
     * @param bool $refresh Force refresh from database
     * @return array<string, mixed>
     */
    public function get(bool $refresh = false): array
    {
        if ($this->settings === null || $refresh) {
            $saved = get_option(self::OPTION_NAME, []);
            $this->settings = array_merge(self::DEFAULTS, is_array($saved) ? $saved : []);
        }
        return $this->settings;
    }

    /**
     * Get a single setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function getValue(string $key, $default = null)
    {
        $settings = $this->get();
        return $settings[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Save settings
     *
     * @param array<string, mixed> $settings Settings to save
     * @return bool Success
     */
    public function save(array $settings): bool
    {
        $current = $this->get();
        $merged = array_merge($current, $settings);
        $result = update_option(self::OPTION_NAME, $merged);

        if ($result) {
            $this->settings = $merged;
        }

        return $result;
    }

    /**
     * Get default settings
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Get max width setting
     */
    public function getMaxWidth(): int
    {
        return (int) $this->getValue('max_width', 1024);
    }

    /**
     * Get max height setting
     */
    public function getMaxHeight(): int
    {
        return (int) $this->getValue('max_height', 1024);
    }

    /**
     * Get quality setting
     */
    public function getQuality(): int
    {
        return (int) $this->getValue('quality', 90);
    }

    /**
     * Get output format
     */
    public function getFormat(): string
    {
        return (string) $this->getValue('format', 'jpeg');
    }

    /**
     * Get background color
     */
    public function getBgColor(): string
    {
        return (string) $this->getValue('bgcolor', 'white');
    }

    /**
     * Get page number to extract (0-indexed)
     */
    public function getPage(): int
    {
        return (int) $this->getValue('page', 0);
    }

    /**
     * Check if auto-generation is enabled
     */
    public function isAutoGenerateEnabled(): bool
    {
        return (bool) $this->getValue('auto_generate', true);
    }

    /**
     * Check if should set as featured image
     */
    public function shouldSetFeatured(): bool
    {
        return (bool) $this->getValue('set_featured', true);
    }


    /**
     * Get file extension for output format
     */
    public function getFileExtension(): string
    {
        $format = $this->getFormat();
        switch ($format) {
            case 'png':
                return 'png';
            case 'webp':
                return 'webp';
            default:
                return 'jpg';
        }
    }

    /**
     * Get MIME type for output format
     */
    public function getMimeType(): string
    {
        $format = $this->getFormat();
        switch ($format) {
            case 'png':
                return 'image/png';
            case 'webp':
                return 'image/webp';
            default:
                return 'image/jpeg';
        }
    }

    /**
     * Get default insert size
     */
    public function getInsertSize(): string
    {
        return (string) $this->getValue('insert_size', 'medium');
    }

    /**
     * Get default insert type (image, title, custom)
     */
    public function getInsertType(): string
    {
        return (string) $this->getValue('insert_type', 'image');
    }

    /**
     * Get default insert link target (file, none)
     */
    public function getInsertLink(): string
    {
        return (string) $this->getValue('insert_link', 'file');
    }

    /**
     * Get custom HTML template
     */
    public function getCustomHtml(): string
    {
        return (string) $this->getValue('custom_html', '');
    }

    /**
     * Check if should display thumbnail as PDF icon
     */
    public function shouldDisplayThumbnailIcon(): bool
    {
        return (bool) $this->getValue('display_thumbnail_icon', true);
    }

    /**
     * Check if should hide generated images in media library
     */
    public function shouldHideGeneratedImages(): bool
    {
        return (bool) $this->getValue('hide_generated_images', true);
    }

    /**
     * Check if should keep images on uninstall
     */
    public function shouldKeepOnUninstall(): bool
    {
        return (bool) $this->getValue('keep_on_uninstall', false);
    }
}
