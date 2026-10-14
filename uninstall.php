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

// The test for "a post shows this image" that regenerating makes too, as a
// file of plain functions: WordPress runs this without loading the plugin.
require_once __DIR__ . '/includes/ContentUse.php';

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

    // A post whose featured image is a PDF showed that PDF's thumbnail, and
    // without the plugin it would show nothing. Point it at the thumbnail
    // itself, with or without "Keep Images on Uninstall": the post looks as
    // it did, and the image counts as used below (review of 1.4.33).
    $rapls_pic_moved = rapls_pic_uninstall_move_featured();

    if (!$rapls_pic_keep_images) {
        // Delete all generated thumbnail images

        // Every thumbnail this plugin made, with its file and its PDF
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
        $rapls_pic_thumbnails = $wpdb->get_results(
            "SELECT marker.post_id AS id, file.meta_value AS file, source.meta_value AS pdf, backup.meta_value AS backup
             FROM {$wpdb->postmeta} marker
             LEFT JOIN {$wpdb->postmeta} file ON file.post_id = marker.post_id AND file.meta_key = '_wp_attached_file'
             LEFT JOIN {$wpdb->postmeta} source ON source.post_id = marker.post_id AND source.meta_key = '_rapls_pic_source_pdf'
             LEFT JOIN {$wpdb->postmeta} backup ON backup.post_id = marker.post_id AND backup.meta_key = '_wp_attachment_backup_sizes'
             WHERE marker.meta_key = '_rapls_pic_is_thumbnail' AND marker.meta_value = '1'",
            ARRAY_A
        );
        $rapls_pic_failed = !is_array($rapls_pic_thumbnails) || '' !== (string) $wpdb->last_error;
        $rapls_pic_thumbnails = is_array($rapls_pic_thumbnails) ? $rapls_pic_thumbnails : [];

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
        $rapls_pic_failed = $rapls_pic_failed || !$rapls_pic_moved || !is_array($rapls_pic_used) || '' !== (string) $wpdb->last_error;
        // Keyed by ID: looked up once per thumbnail below, and in_array() over
        // a list made that thumbnails x used (review of 1.4.34).
        $rapls_pic_used = is_array($rapls_pic_used) ? array_fill_keys(array_map('intval', $rapls_pic_used), true) : [];

        // Also kept: those a post's content shows. An image or gallery block
        // given the PDF holds the thumbnail's address, and the classic editor's
        // insert does too; deleting the file breaks the picture in the post.
        // Only the ones not already kept are looked for, and the reading
        // stops once every one of them has been found.
        $rapls_pic_unused = array_values(array_filter($rapls_pic_thumbnails, static function ($rapls_pic_row) use ($rapls_pic_used): bool {
            return !isset($rapls_pic_used[(int) $rapls_pic_row['id']]);
        }));
        $rapls_pic_shown = $rapls_pic_failed ? null : rapls_pic_uninstall_shown_in_content($rapls_pic_unused);
        $rapls_pic_failed = $rapls_pic_failed || null === $rapls_pic_shown;

        if (!$rapls_pic_failed) {
            $rapls_pic_used += array_fill_keys($rapls_pic_shown, true);

            foreach ($rapls_pic_thumbnails as $rapls_pic_thumbnail) {
                $rapls_pic_thumbnail_id = (int) $rapls_pic_thumbnail['id'];
                if (isset($rapls_pic_used[$rapls_pic_thumbnail_id])) {
                    continue;
                }

                // Delete the attachment (this also deletes the file)
                wp_delete_attachment($rapls_pic_thumbnail_id, true);
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

/**
 * Point featured images that are PDFs at those PDFs' thumbnails
 *
 * Through update_post_meta(), one post at a time, so an object cache sees
 * the change. Only where the thumbnail is still an attachment.
 *
 * @return bool False when the lookup failed.
 */
function rapls_pic_uninstall_move_featured(): bool
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
    $rapls_pic_rows = $wpdb->get_results(
        "SELECT used.post_id AS post, used.meta_value AS pdf, thumb.meta_value AS thumbnail
         FROM {$wpdb->postmeta} used
         INNER JOIN {$wpdb->postmeta} thumb ON thumb.post_id = CAST(used.meta_value AS UNSIGNED) AND thumb.meta_key = '_rapls_pic_thumbnail_id'
         INNER JOIN {$wpdb->posts} image ON image.ID = CAST(thumb.meta_value AS UNSIGNED) AND image.post_type = 'attachment'
         WHERE used.meta_key = '_thumbnail_id'",
        ARRAY_A
    );
    if (!is_array($rapls_pic_rows) || '' !== (string) $wpdb->last_error) {
        return false;
    }

    foreach ($rapls_pic_rows as $rapls_pic_row) {
        update_post_meta((int) $rapls_pic_row['post'], '_thumbnail_id', (int) $rapls_pic_row['thumbnail'], $rapls_pic_row['pdf']);
    }

    return true;
}

/**
 * The thumbnails some post's content shows
 *
 * ContentUse makes the test, the same one regenerating and deleting a PDF
 * make: the file name in any size, before and after an edit, compared whole;
 * or a "wp-image-" class, an image block, a gallery block or a [gallery]
 * shortcode naming the image or the PDF it was made from -- a PDF in a
 * gallery showed its thumbnail, and without the plugin it shows nothing, so
 * the image is kept for the post to be pointed at again (review of 1.4.36).
 * This plugin's own shortcodes naming the PDF count the same way (review
 * after 1.4.37), and so does either ID wherever an ID could stand, as page
 * builders keep an image (review after 1.4.38).
 *
 * The content and the excerpt are read: not widgets, not options, not a page
 * builder's own meta. Revisions and auto-drafts are not read: a revision
 * kept a picture taken out of the post long ago. A trashed post is: it can
 * be restored with its pictures (review after 1.4.37). Every other post
 * is read, 200 at a time, unless every thumbnail has been found sooner. On a
 * very large site that can outlast the request; if it does, nothing has
 * been deleted yet, and the plugin's settings and markers are what is left
 * behind.
 *
 * @param array<int, array<string, mixed>> $thumbnails Rows with id, file, pdf and backup.
 * @return int[]|null Null when the posts could not be read.
 */
function rapls_pic_uninstall_shown_in_content(array $thumbnails): ?array
{
    global $wpdb;

    $rapls_pic_by_stem = [];
    $rapls_pic_by_id = [];
    foreach ($thumbnails as $rapls_pic_thumbnail) {
        $rapls_pic_id = (int) $rapls_pic_thumbnail['id'];
        $rapls_pic_backup = isset($rapls_pic_thumbnail['backup']) ? maybe_unserialize($rapls_pic_thumbnail['backup']) : [];
        foreach (\Rapls\PDFImageCreator\ContentUse::stemsOf((string) $rapls_pic_thumbnail['file'], $rapls_pic_backup) as $rapls_pic_stem) {
            $rapls_pic_by_stem[$rapls_pic_stem][] = $rapls_pic_id;
        }
        $rapls_pic_by_id[$rapls_pic_id][] = $rapls_pic_id;
        if ((int) $rapls_pic_thumbnail['pdf'] > 0) {
            $rapls_pic_by_id[(int) $rapls_pic_thumbnail['pdf']][] = $rapls_pic_id;
        }
    }
    if ([] === $rapls_pic_by_id) {
        return [];
    }

    $rapls_pic_skip_types = "'" . implode("', '", \Rapls\PDFImageCreator\ContentUse::SKIP_TYPES) . "'";
    $rapls_pic_skip_statuses = "'" . implode("', '", \Rapls\PDFImageCreator\ContentUse::SKIP_STATUSES) . "'";
    $rapls_pic_shown = []; // Keyed by ID.
    $rapls_pic_after = 0;
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall cleanup requires direct query; constants only
        $rapls_pic_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ({$rapls_pic_skip_types}) AND post_status NOT IN ({$rapls_pic_skip_statuses}) AND (post_content <> '' OR post_excerpt <> '') ORDER BY ID LIMIT 200",
                $rapls_pic_after
            ),
            ARRAY_A
        );
        if (!is_array($rapls_pic_posts) || '' !== (string) $wpdb->last_error) {
            return null;
        }

        foreach ($rapls_pic_posts as $rapls_pic_post) {
            $rapls_pic_after = (int) $rapls_pic_post['ID'];
            $rapls_pic_content = (string) $rapls_pic_post['post_content'] . "\n" . (string) ($rapls_pic_post['post_excerpt'] ?? '');

            // stemsShown(): a post the pattern cannot be run over still
            // keeps every image whose name it holds (review after 1.4.37).
            foreach (\Rapls\PDFImageCreator\ContentUse::stemsShown($rapls_pic_content, array_keys($rapls_pic_by_stem)) as $rapls_pic_stem) {
                $rapls_pic_shown += array_fill_keys($rapls_pic_by_stem[$rapls_pic_stem], true);
            }
            // Any number where an ID could stand counts, as for
            // regenerating: page builders keep an image by ID alone (review
            // after 1.4.38). Not read: every image is kept.
            $rapls_pic_context = \Rapls\PDFImageCreator\ContentUse::idsInContext($rapls_pic_content);
            if (null === $rapls_pic_context) {
                return array_values(array_unique(array_map('intval', array_column($thumbnails, 'id'))));
            }
            $rapls_pic_named = \Rapls\PDFImageCreator\ContentUse::idsIn($rapls_pic_content) + $rapls_pic_context + \Rapls\PDFImageCreator\ContentUse::shortcodePdfIdsIn($rapls_pic_content);
            foreach (array_intersect_key($rapls_pic_by_id, $rapls_pic_named) as $rapls_pic_ids) {
                $rapls_pic_shown += array_fill_keys($rapls_pic_ids, true);
            }
        }

        // Every thumbnail found: the rest of the site cannot keep any more.
        if (count($rapls_pic_shown) >= count($thumbnails)) {
            break;
        }
    } while (200 === count($rapls_pic_posts));

    return array_keys($rapls_pic_shown);
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
