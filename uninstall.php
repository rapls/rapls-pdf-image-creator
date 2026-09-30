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

// Clear translation file cache to prevent stale .l10n.php include() warnings.
// WordPress caches the file list from wp-content/languages/plugins/ for up to 1 hour.
wp_cache_delete(md5(WP_LANG_DIR . '/plugins/'), 'translation_files');

/**
 * Everything this plugin keeps in one site's tables
 *
 * One site at a time: WordPress includes this file once, on the site the
 * plugin is deleted from, and on a network the other sites' settings,
 * markers and -- with "Keep Images on Uninstall" off -- generated images
 * were left behind (R64-01).
 */
function rapls_pic_uninstall_site(): void
{
    global $wpdb;

    // Get plugin settings
    $rapls_pic_settings = get_option('rapls_pic_settings', []);

    // Check if we should keep generated images
    $rapls_pic_keep_images = !empty($rapls_pic_settings['keep_on_uninstall']);

    if (!$rapls_pic_keep_images) {
        // Delete all generated thumbnail images

        // Find all thumbnails created by this plugin
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
        $rapls_pic_thumbnail_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rapls_pic_is_thumbnail' AND meta_value = '1'"
        );

        // Kept: those something other than the PDF they were made from still
        // uses -- a post's featured image, a translated copy of the PDF sharing
        // it. Deleting a thumbnail and regenerating keep those since 1.4.8, and
        // uninstalling deleted them all the same. They stay as ordinary images;
        // their markers go with everyone else's below. One query for all of
        // them, and when it fails nothing is deleted: an image kept can still be
        // removed by hand, one deleted cannot be brought back.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
        $rapls_pic_used = $wpdb->get_col(
            "SELECT DISTINCT used.meta_value FROM {$wpdb->postmeta} used
             INNER JOIN {$wpdb->postmeta} source ON source.post_id = CAST(used.meta_value AS UNSIGNED) AND source.meta_key = '_rapls_pic_source_pdf'
             WHERE used.meta_key IN ('_thumbnail_id', '_rapls_pic_thumbnail_id')
               AND used.post_id <> CAST(source.meta_value AS UNSIGNED)"
        );
        $rapls_pic_used_failed = !is_array($rapls_pic_used) || '' !== (string) $wpdb->last_error;
        $rapls_pic_used = is_array($rapls_pic_used) ? array_map('intval', $rapls_pic_used) : [];

        if (!empty($rapls_pic_thumbnail_ids) && !$rapls_pic_used_failed) {
            foreach ($rapls_pic_thumbnail_ids as $rapls_pic_thumbnail_id) {
                if (in_array((int) $rapls_pic_thumbnail_id, $rapls_pic_used, true)) {
                    continue;
                }

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
    delete_option('rapls_pic_version');
    delete_option('rapls_pic_review_prompt');
    delete_option('rapls_pic_activated_at');
    delete_option('rapls_pic_color_notice');
    delete_option('rapls_pic_color_diagnostics');
    delete_option('rapls_pic_engine_unavailable');
    delete_option('rapls_pic_last_failure');

    // Cached ICC profile locations, the cached policy.xml lookup and the
    // activation warning
    delete_transient('rapls_pic_icc_path_srgb');
    delete_transient('rapls_pic_icc_path_cmyk');
    delete_transient('rapls_pic_pdf_policy');
    delete_transient('rapls_pic_pdf_read');
    delete_transient('rapls_pic_cmyk_render');
    delete_transient('rapls_pic_page_select');
    delete_transient('rapls_pic_cmyk_delegate');
    delete_transient('rapls_pic_page_suffix');
    delete_transient('rapls_pic_gs_options');
    delete_transient('rapls_pic_activation_notice');
}

if (is_multisite() && function_exists('get_sites')) {
    // Every site of every network, each by its own settings.
    foreach (get_sites(['fields' => 'ids', 'number' => 0, 'network_id' => 0]) as $rapls_pic_site) {
        switch_to_blog((int) $rapls_pic_site);

        try {
            rapls_pic_uninstall_site();
        } finally {
            restore_current_blog();
        }
    }
} else {
    rapls_pic_uninstall_site();
}

// The per-user notice dismissal flags: users are the network's, so once.
delete_metadata('user', 0, 'rapls_pic_color_notice_dismissed', '', true);
delete_metadata('user', 0, 'rapls_pic_engine_notice_dismissed', '', true);
