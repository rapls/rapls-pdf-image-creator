<?php
/**
 * Uninstall handler
 *
 * This file is executed when the plugin is uninstalled.
 *
 * @package PDFImageCreator
 */

// Exit if not called by WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Ensure .l10n.php exists in WP global languages directory to prevent
// include() warnings after plugin files are deleted.
$rapls_pic_locale = determine_locale();
if ($rapls_pic_locale && 'en_US' !== $rapls_pic_locale) {
    $rapls_pic_l10n_source = __DIR__ . '/languages/rapls-pdf-image-creator-' . $rapls_pic_locale . '.l10n.php';
    $rapls_pic_l10n_dest = WP_LANG_DIR . '/plugins/rapls-pdf-image-creator-' . $rapls_pic_locale . '.l10n.php';
    if (file_exists($rapls_pic_l10n_source) && !file_exists($rapls_pic_l10n_dest)) {
        @copy($rapls_pic_l10n_source, $rapls_pic_l10n_dest);
    }
}

// Get plugin settings
$rapls_pic_settings = get_option('rapls_pic_settings', []);

// Check if we should keep generated images
$rapls_pic_keep_images = !empty($rapls_pic_settings['keep_on_uninstall']);

if (!$rapls_pic_keep_images) {
    // Delete all generated thumbnail images
    global $wpdb;

    // Find all thumbnails created by this plugin
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
    $rapls_pic_thumbnail_ids = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rapls_pic_is_thumbnail' AND meta_value = '1'"
    );

    if (!empty($rapls_pic_thumbnail_ids)) {
        foreach ($rapls_pic_thumbnail_ids as $rapls_pic_thumbnail_id) {
            // Delete the attachment (this also deletes the file)
            wp_delete_attachment((int) $rapls_pic_thumbnail_id, true);
        }
    }

    // Clean up PDF meta data
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_thumbnail_id']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_is_thumbnail']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_source_pdf']);
} else {
    // Just remove the plugin-specific meta, but keep the images
    global $wpdb;

    // Remove the thumbnail marker so images become regular images
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_is_thumbnail']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_source_pdf']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup requires direct query
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_rapls_pic_thumbnail_id']);
}

// Delete plugin options
delete_option('rapls_pic_settings');
