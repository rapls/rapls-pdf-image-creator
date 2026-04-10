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
 * Plugin Name: Rapls PDF Image Creator
 * Plugin URI:  https://raplsworks.com/rapls-pdf-image-creator-guide/
 * Description: Automatically generate thumbnail images from PDF files uploaded to the Media Library.
 * Version:     1.0.9.3
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
define('RAPLS_PIC_VERSION', '1.0.9.3');
define('RAPLS_PIC_PLUGIN_FILE', __FILE__);
define('RAPLS_PIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RAPLS_PIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RAPLS_PIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

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

// Load translations from plugin directory to prevent .l10n.php warnings
add_action('init', function (): void {
    load_plugin_textdomain('rapls-pdf-image-creator', false, dirname(RAPLS_PIC_PLUGIN_BASENAME) . '/languages');
}, 1);

// Load plugin
add_action('plugins_loaded', function (): void {
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

    // Set default options
    $defaults = [
        'max_width' => 1024,
        'max_height' => 1024,
        'quality' => 90,
        'format' => 'jpeg',
        'bgcolor' => 'white',
        'page' => 0,
        'auto_generate' => true,
        'set_featured' => true,
    ];

    if (!get_option('rapls_pic_settings')) {
        add_option('rapls_pic_settings', $defaults);
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
