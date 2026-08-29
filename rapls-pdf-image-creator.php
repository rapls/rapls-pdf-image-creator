<?php

/**
 * Rapls PDF Image Creator
 *
 * @package     RaplsPDFImageCreator
 * @author      Rapls Works
 * @copyright   2026 Rapls Works
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Rapls PDF Image Creator – PDF Thumbnails & Featured Images
 * Plugin URI:  https://raplsworks.com/plugins/rapls-pdf-image-creator/
 * Description: The first page of each uploaded PDF becomes an image in every registered size, ready to use as a post cover or inside content. Needs ImageMagick.
 * Version:     1.3.0
 * Author:      Rapls Works
 * Author URI:  https://raplsworks.com
 * Text Domain: rapls-pdf-image-creator
 * Domain Path: /languages
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

/*
Rapls PDF Image Creator is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Rapls PDF Image Creator is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Rapls PDF Image Creator. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('RAPLS_PIC_VERSION', '1.3.0');
define('RAPLS_PIC_PLUGIN_FILE', __FILE__);
define('RAPLS_PIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAPLS_PIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RAPLS_PIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Transient set at activation when the server cannot render PDFs, so the
// first admin page after activation can say so.
define('RAPLS_PIC_ACTIVATION_NOTICE', 'rapls_pic_activation_notice');

// Autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'Rapls\\PDFImageCreator\\';
    $baseDir = RAPLS_PIC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Prevent WordPress JIT loader from trying to include non-existent .l10n.php
// in the global languages directory (wp-content/languages/plugins/).
// This filter fires BEFORE the include() call, so the warning never occurs.
add_filter('override_load_textdomain', function ($override, $domain, $mofile) {
    if ('rapls-pdf-image-creator' !== $domain) {
        return $override;
    }

    // Block loading from global directory — we load from plugin directory instead
    if (0 === strpos($mofile, WP_LANG_DIR)) {
        return true;
    }

    return $override;
}, 10, 3);

// Clear stale translation file cache on deactivation.
// WordPress caches glob() results for up to 1 hour (wp_cache 'translation_files').
// After plugin deactivation/deletion, stale cache entries cause
// wp_get_l10n_php_file_data() to include() files that no longer exist.
register_deactivation_hook(RAPLS_PIC_PLUGIN_FILE, function (): void {
    wp_cache_delete(md5(WP_LANG_DIR . '/plugins/'), 'translation_files');
    delete_transient(RAPLS_PIC_ACTIVATION_NOTICE);
});

// Load plugin
add_action('plugins_loaded', function (): void {
    // Load translations from plugin directory (not blocked by our filter
    // because the path is under RAPLS_PIC_PLUGIN_DIR, not WP_LANG_DIR)
    $locale = determine_locale();
    if ($locale && 'en_US' !== $locale) {
        load_textdomain(
            'rapls-pdf-image-creator',
            RAPLS_PIC_PLUGIN_DIR . 'languages/rapls-pdf-image-creator-' . $locale . '.mo',
            $locale
        );
    }

    // Initialize plugin
    $plugin = \Rapls\PDFImageCreator\Plugin::getInstance();
    $plugin->init();
});

// Activation hook
register_activation_hook(__FILE__, function (): void {
    // Check requirements
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(RAPLS_PIC_PLUGIN_BASENAME);
        wp_die(
            esc_html__('Rapls PDF Image Creator requires PHP 7.4 or higher.', 'rapls-pdf-image-creator'),
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }

    // Set default options. Taken from Settings so the two cannot drift.
    if (!get_option(\Rapls\PDFImageCreator\Settings::OPTION_NAME)) {
        add_option(\Rapls\PDFImageCreator\Settings::OPTION_NAME, \Rapls\PDFImageCreator\Settings::DEFAULTS);
    }

    // Stamping the version here is what lets Plugin::maybeUpgrade() tell a
    // fresh install apart from an upgrade that predates the option.
    update_option('rapls_pic_version', RAPLS_PIC_VERSION, false);

    // Activating on a server without ImageMagick used to succeed in complete
    // silence: uploads produced no thumbnail and nothing said why. Record the
    // reason now so the next admin page can say it out loud.
    delete_transient(RAPLS_PIC_ACTIVATION_NOTICE);
    try {
        $rapls_pic_status = (new \Rapls\PDFImageCreator\Engine\ImagickEngine())->getAvailabilityStatus();
        if ('ok' !== $rapls_pic_status['code']) {
            set_transient(RAPLS_PIC_ACTIVATION_NOTICE, $rapls_pic_status['code'], WEEK_IN_SECONDS);
        }
    } catch (\Throwable $e) {
        // A broken Imagick build must never break activation.
        set_transient(RAPLS_PIC_ACTIVATION_NOTICE, 'error', WEEK_IN_SECONDS);
    }
});

/**
 * Template function: Get thumbnail URL
 *
 * @param int $pdfId PDF attachment ID
 * @param string $size Image size
 * @return string|null Thumbnail URL or null
 */
function rapls_pic_get_thumbnail_url(int $pdfId, string $size = 'thumbnail'): ?string
{
    return \Rapls\PDFImageCreator\Plugin::getInstance()->getGenerator()->getThumbnailUrl($pdfId, $size);
}

/**
 * Template function: Get thumbnail ID
 *
 * @param int $pdfId PDF attachment ID
 * @return int|null Thumbnail attachment ID or null
 */
function rapls_pic_get_thumbnail_id(int $pdfId): ?int
{
    return \Rapls\PDFImageCreator\Plugin::getInstance()->getGenerator()->getThumbnailId($pdfId);
}

/**
 * Template function: Get thumbnail image tag
 *
 * @param int $pdfId PDF attachment ID
 * @param string $size Image size
 * @param array<string, mixed> $attr Image attributes
 * @return string Image HTML or empty string
 */
function rapls_pic_get_thumbnail_image(int $pdfId, string $size = 'thumbnail', array $attr = []): string
{
    return \Rapls\PDFImageCreator\Plugin::getInstance()->getGenerator()->getThumbnailImage($pdfId, $size, $attr);
}

/**
 * Template function: Check if PDF has thumbnail
 *
 * @param int $pdfId PDF attachment ID
 * @return bool True if has thumbnail
 */
function rapls_pic_has_thumbnail(int $pdfId): bool
{
    return \Rapls\PDFImageCreator\Plugin::getInstance()->getGenerator()->hasThumbnail($pdfId);
}

/**
 * Template function: Generate thumbnail
 *
 * @param int $pdfId PDF attachment ID
 * @param bool $force Force regeneration
 * @return int|null Thumbnail ID or null on failure
 */
function rapls_pic_generate_thumbnail(int $pdfId, bool $force = false): ?int
{
    return \Rapls\PDFImageCreator\Plugin::getInstance()->getGenerator()->generate($pdfId, $force);
}
