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
     *
     * Public because the activation hook seeds the option from here. It used
     * to keep its own copy of this list, which quietly went out of date.
     */
    public const DEFAULTS = [
        'max_width' => 1024,
        'max_height' => 1024,
        'resolution' => 150,
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
        // Without $default, DEFAULTS is the default. The getters below asked
        // with the same numbers written out a second time (review after
        // 1.4.31); they now leave it to DEFAULTS, the one place they live.
        $settings = $this->get();
        return $settings[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * Save settings
     *
     * Not called by this plugin, which saves through the Settings API, and
     * kept: Plugin::getInstance()->getSettings() hands this object to any
     * code on the site, and taking a public method away is a fatal error in
     * whoever called it (review after 1.4.33). The same goes for
     * getDefaults().
     *
     * @param array<string, mixed> $settings Settings to save
     * @return bool Whether the stored settings now hold these values
     */
    public function save(array $settings): bool
    {
        // Merged onto what is stored now, not onto this instance's copy: a
        // copy read earlier in the request wrote back values someone else had
        // changed since. And judged by reading back, not by update_option():
        // false there also means "already so", which is not a failure, and
        // the copy was then left stale (R59-03).
        $merged = array_merge($this->get(true), $settings);
        update_option(self::OPTION_NAME, $merged);
        $stored = $this->get(true);

        foreach ($settings as $key => $value) {
            // Loose: an option can come back with '1' for 1.
            if (!array_key_exists($key, $stored) || $stored[$key] != $value) {
                return false;
            }
        }

        return true;
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
        return (int) $this->getValue('max_width');
    }

    /**
     * Get max height setting
     */
    public function getMaxHeight(): int
    {
        return (int) $this->getValue('max_height');
    }

    /**
     * Get rendering resolution in DPI
     *
     * This is the density Imagick renders the PDF at, applied before the page
     * is read. It sets the size of the source raster; the thumbnail is then
     * scaled down to the max dimensions, never up.
     */
    public function getResolution(): int
    {
        return (int) $this->getValue('resolution');
    }

    /**
     * Get quality setting
     */
    public function getQuality(): int
    {
        return (int) $this->getValue('quality');
    }

    /**
     * Get output format
     */
    public function getFormat(): string
    {
        return (string) $this->getValue('format');
    }

    /**
     * The file extension of the output format
     *
     * Nothing in this plugin calls it. It stays because it was public, and
     * code elsewhere that called it through Plugin::getInstance()->getSettings()
     * would stop with a fatal error if it went (review after 1.4.38).
     *
     * @deprecated 1.4.45 Read getFormat() instead.
     */
    public function getFileExtension(): string
    {
        switch ($this->getFormat()) {
            case 'png':
                return 'png';
            case 'webp':
                return 'webp';
            default:
                return 'jpg';
        }
    }

    /**
     * The MIME type of the output format
     *
     * Kept for the same reason as getFileExtension().
     *
     * @deprecated 1.4.45 Read getFormat() instead.
     */
    public function getMimeType(): string
    {
        switch ($this->getFormat()) {
            case 'png':
                return 'image/png';
            case 'webp':
                return 'image/webp';
            default:
                return 'image/jpeg';
        }
    }

    /**
     * Get background color
     */
    public function getBgColor(): string
    {
        return (string) $this->getValue('bgcolor');
    }

    /**
     * Get page number to extract (0-indexed)
     */
    public function getPage(): int
    {
        return (int) $this->getValue('page');
    }

    /**
     * Check if auto-generation is enabled
     */
    public function isAutoGenerateEnabled(): bool
    {
        return (bool) $this->getValue('auto_generate');
    }

    /**
     * Check if should set as featured image
     */
    public function shouldSetFeatured(): bool
    {
        return (bool) $this->getValue('set_featured');
    }


    /**
     * Get default insert size
     */
    public function getInsertSize(): string
    {
        return (string) $this->getValue('insert_size');
    }

    /**
     * Get default insert type (image, title, custom)
     */
    public function getInsertType(): string
    {
        return (string) $this->getValue('insert_type');
    }

    /**
     * Get default insert link target (file, attachment, none)
     *
     * The media window's Link To starts here for a PDF, and a choice made
     * there wins; see Inserter::linkFor() and admin/js/insert-link.js.
     */
    public function getInsertLink(): string
    {
        return (string) $this->getValue('insert_link');
    }

    /**
     * Get custom HTML template
     */
    public function getCustomHtml(): string
    {
        return (string) $this->getValue('custom_html');
    }

    /**
     * Check if should display thumbnail as PDF icon
     */
    public function shouldDisplayThumbnailIcon(): bool
    {
        return (bool) $this->getValue('display_thumbnail_icon');
    }

    /**
     * Check if should hide generated images in media library
     */
    public function shouldHideGeneratedImages(): bool
    {
        return (bool) $this->getValue('hide_generated_images');
    }

    /**
     * Check if should keep images on uninstall
     */
    public function shouldKeepOnUninstall(): bool
    {
        return (bool) $this->getValue('keep_on_uninstall');
    }
}
