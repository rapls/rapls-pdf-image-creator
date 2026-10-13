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
            "SELECT marker.post_id AS id, file.meta_value AS file, source.meta_value AS pdf
             FROM {$wpdb->postmeta} marker
             LEFT JOIN {$wpdb->postmeta} file ON file.post_id = marker.post_id AND file.meta_key = '_wp_attached_file'
             LEFT JOIN {$wpdb->postmeta} source ON source.post_id = marker.post_id AND source.meta_key = '_rapls_pic_source_pdf'
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
 * The file name of an image, without size, "-scaled" or extension
 *
 * "2026/10/a-pdf-thumbnail-300x200.jpg" and ".../a-pdf-thumbnail.jpg" give
 * the same answer, so a post showing any size of a thumbnail is found.
 *
 * @param string $name A path, an address or a file name.
 * @return string
 */
function rapls_pic_uninstall_stem(string $name): string
{
    $name = strtolower(wp_basename($name));
    $name = (string) preg_replace('/\.[a-z0-9]+$/', '', $name);

    return (string) preg_replace('/(?:-scaled|-rotated|-\d+x\d+)+$/', '', $name);
}

/**
 * The thumbnails some post's content shows
 *
 * By file name, in any size, and by a "wp-image-" class naming the image or
 * the PDF it was made from. Matching on the name alone keeps an image whose
 * name another file shares; that errs towards keeping, which is the side
 * this file errs on.
 *
 * Only post_content is read: not widgets, not options, not a page builder's
 * own meta. Every post is read, 200 at a time, unless every thumbnail has
 * been found sooner. On a very large site that can outlast the request; if
 * it does, nothing has been deleted yet, and the plugin's settings and
 * markers are what is left behind.
 *
 * @param array<int, array<string, mixed>> $thumbnails Rows with id, file and pdf.
 * @return int[]|null Null when the posts could not be read.
 */
function rapls_pic_uninstall_shown_in_content(array $thumbnails): ?array
{
    global $wpdb;

    $rapls_pic_by_stem = [];
    $rapls_pic_by_id = [];
    foreach ($thumbnails as $rapls_pic_thumbnail) {
        $rapls_pic_id = (int) $rapls_pic_thumbnail['id'];
        if ('' !== (string) $rapls_pic_thumbnail['file']) {
            $rapls_pic_by_stem[rapls_pic_uninstall_stem((string) $rapls_pic_thumbnail['file'])][] = $rapls_pic_id;
        }
        $rapls_pic_by_id[$rapls_pic_id][] = $rapls_pic_id;
        if ((int) $rapls_pic_thumbnail['pdf'] > 0) {
            $rapls_pic_by_id[(int) $rapls_pic_thumbnail['pdf']][] = $rapls_pic_id;
        }
    }
    if ([] === $rapls_pic_by_id) {
        return [];
    }

    $rapls_pic_shown = []; // Keyed by ID.
    $rapls_pic_after = 0;
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires direct query
        $rapls_pic_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_type <> 'attachment' AND post_content <> '' ORDER BY ID LIMIT 200",
                $rapls_pic_after
            ),
            ARRAY_A
        );
        if (!is_array($rapls_pic_posts) || '' !== (string) $wpdb->last_error) {
            return null;
        }

        foreach ($rapls_pic_posts as $rapls_pic_post) {
            $rapls_pic_after = (int) $rapls_pic_post['ID'];
            $rapls_pic_content = (string) $rapls_pic_post['post_content'];

            if (preg_match_all('/([^\/\s"\'<>()=]+)\.(?:jpe?g|png|gif|webp|avif)\b/i', $rapls_pic_content, $rapls_pic_files)) {
                foreach ($rapls_pic_files[0] as $rapls_pic_file) {
                    // Decoded: an address percent-encodes a name that is not
                    // ASCII, and the attached file holds it as it is, so a
                    // post showing "%E8%B3%87%E6%96%99-pdf-thumbnail.jpg" did
                    // not keep 資料-pdf-thumbnail.jpg (review of 1.4.34).
                    $rapls_pic_stem = rapls_pic_uninstall_stem(rawurldecode($rapls_pic_file));
                    if (isset($rapls_pic_by_stem[$rapls_pic_stem])) {
                        $rapls_pic_shown += array_fill_keys($rapls_pic_by_stem[$rapls_pic_stem], true);
                    }
                }
            }
            if (preg_match_all('/wp-image-(\d+)/', $rapls_pic_content, $rapls_pic_classes)) {
                foreach ($rapls_pic_classes[1] as $rapls_pic_class) {
                    if (isset($rapls_pic_by_id[(int) $rapls_pic_class])) {
                        $rapls_pic_shown += array_fill_keys($rapls_pic_by_id[(int) $rapls_pic_class], true);
                    }
                }
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
