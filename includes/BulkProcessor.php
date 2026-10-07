<?php
/**
 * Bulk Processor
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Handles bulk thumbnail generation
 */
final class BulkProcessor
{
    /**
     * Generator instance
     */
    private Generator $generator;

    /**
     * Constructor
     *
     * The settings were taken and kept too, and never read (Codex review of
     * 1.4.26, 5).
     *
     * @param Generator $generator Thumbnail generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Initialize AJAX handlers
     */
    public function init(): void
    {
        add_action('wp_ajax_rapls_pic_bulk_scan', [$this, 'ajaxScan']);
        add_action('wp_ajax_rapls_pic_bulk_next', [$this, 'ajaxNext']);
        add_action('wp_ajax_rapls_pic_bulk_generate', [$this, 'ajaxGenerate']);
        add_action('wp_ajax_rapls_pic_bulk_status', [$this, 'ajaxStatus']);
    }

    /**
     * PDFs looked at per request
     *
     * Scanning, counting and finding the next PDF to draw each read the
     * library a page at a time, from a cursor the browser hands back. Up to
     * 1.4.25 a scan read every PDF twice in one request -- all their IDs held
     * at once, every one's meta looked at -- and sent the browser the whole
     * list: on a large library the request ran out of memory or time before
     * anything started (Codex review of 1.4.25, 5).
     */
    public const PAGE = 200;

    /**
     * Pages looked through for the next PDF to draw in one request
     *
     * When everything after the cursor already has a thumbnail, the search
     * stops here and says where it got to; the browser asks again from there.
     */
    private const SEARCH_PAGES = 5;

    /**
     * AJAX: Scan for PDFs, one page at a time
     *
     * Counts carried by the browser from page to page; the last page, the
     * one that says done, adds the note about what was found.
     */
    public function ajaxScan(): void
    {
        try {
            // Verify nonce - use false to not die on failure
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            // As the Settings page Bulk Generate lives on. upload_files let
            // any author list every PDF and regenerate PDFs that are not
            // theirs, by posting straight here (R55-01).
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
                return;
            }

            $includeExisting = !empty($_POST['include_existing']);
            $after = isset($_POST['after']) ? absint($_POST['after']) : 0;
            $page = $this->statsPage($after);
            $stats = [
                'total' => self::carried('total_pdfs') + $page['total'],
                'with_thumbnail' => self::carried('with_thumbnail') + $page['with_thumbnail'],
            ];
            $todo = $includeExisting ? $stats['total'] : $stats['total'] - $stats['with_thumbnail'];

            if (!$page['done']) {
                wp_send_json_success([
                    'done' => false,
                    'after' => $page['after'],
                    'total' => $todo,
                    'total_pdfs' => $stats['total'],
                    'with_thumbnail' => $stats['with_thumbnail'],
                ]);
                return;
            }

            // "PDFs found: 0" on its own leaves the reader with no idea
            // whether the library is empty, whether everything is already
            // done, or whether something is broken. Those are three different
            // situations and only one of them is a problem.
            $rows = $this->countPdfRows();
            $note = '';

            // Whether the screen can turn this dead end into an action.
            $retry = false;

            if (0 === $todo) {
                if (0 === $rows['total']) {
                    $note = __('There are no PDF files in the Media Library. Uploading a PDF over FTP or SSH does not add it — it has to go through Media > Add New.', 'rapls-pdf-image-creator');
                } elseif (0 === $stats['total']) {
                    // The rows are there and the query did not see them.
                    $note = sprintf(
                        /* translators: 1: number of PDF rows, 2: post statuses and counts, e.g. "inherit=3, private=1" */
                        __('The database has %1$d PDF attachment(s) (%2$s) but the query returned none. Something is filtering it — usually another plugin on pre_get_posts. Try deactivating other plugins and scanning again.', 'rapls-pdf-image-creator'),
                        $rows['total'],
                        $rows['statuses']
                    );
                } elseif (!$includeExisting) {
                    $retry = true;
                    $note = sprintf(
                        /* translators: %d: number of PDFs that already have a thumbnail */
                        _n(
                            '%d PDF already has a thumbnail. Tick "Include PDFs that already have thumbnails" above to generate it again.',
                            'All %d PDFs already have thumbnails. Tick "Include PDFs that already have thumbnails" above to generate them again.',
                            $stats['total'],
                            'rapls-pdf-image-creator'
                        ),
                        $stats['total']
                    );
                }
            }

            wp_send_json_success([
                'done' => true,
                'after' => $page['after'],
                'total' => $todo,
                'total_pdfs' => $stats['total'],
                'with_thumbnail' => $stats['with_thumbnail'],
                'rows' => $rows['total'],
                'statuses' => $rows['statuses'],
                'note' => $note,
                // Telling someone to go and tick a box above is a poor answer
                // when the screen could just do it. The box also resets on
                // every page load, so "tick it and scan again" is easy to have
                // already done and easy to lose.
                'retry' => $retry,
                'retry_label' => $retry
                    ? __('Scan again, including these', 'rapls-pdf-image-creator')
                    : '',
            ]);
        } catch (\Throwable $e) {
            // Log error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Rapls PDF Image Creator: ' . $e->getMessage());
            }
            wp_send_json_error([
                'message' => __('An error occurred while scanning for PDFs.', 'rapls-pdf-image-creator'),
            ]);
        }
    }

    /**
     * AJAX: The next PDF after a cursor that needs drawing
     *
     * Every PDF with `force`, otherwise those without a thumbnail. Asked
     * apart from drawing it, so a draw that never answers -- a timeout, a
     * fatal error -- still leaves the browser knowing which PDF that was,
     * and it goes on to the next one as it always has.
     */
    public function ajaxNext(): void
    {
        try {
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
                return;
            }

            $next = $this->nextToGenerate(isset($_POST['after']) ? absint($_POST['after']) : 0, !empty($_POST['force']));

            wp_send_json_success([
                'pdf_id' => $next['pdf_id'],
                'filename' => null !== $next['pdf_id'] ? basename(get_attached_file($next['pdf_id']) ?: '') : '',
                'after' => $next['after'],
                'done' => $next['done'],
            ]);
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Rapls PDF Image Creator: ' . $e->getMessage());
            }
            wp_send_json_error([
                'message' => __('An error occurred while scanning for PDFs.', 'rapls-pdf-image-creator'),
            ]);
        }
    }

    /**
     * AJAX: Generate single thumbnail
     */
    public function ajaxGenerate(): void
    {
        try {
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            // As the Settings page Bulk Generate lives on. upload_files let
            // any author list every PDF and regenerate PDFs that are not
            // theirs, by posting straight here (R55-01).
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
                return;
            }

            $pdfId = isset($_POST['pdf_id']) ? absint($_POST['pdf_id']) : 0;
            $force = !empty($_POST['force']);

            if (!$pdfId) {
                wp_send_json_error(['message' => __('Invalid PDF ID.', 'rapls-pdf-image-creator')]);
                return;
            }

            // And this PDF, as the Media Library's own link asks.
            if (!current_user_can('edit_post', $pdfId)) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
                return;
            }

            // Verify it's a PDF
            $mimeType = get_post_mime_type($pdfId);
            if ($mimeType !== 'application/pdf') {
                wp_send_json_error(['message' => __('Not a PDF file.', 'rapls-pdf-image-creator')]);
                return;
            }

            $result = $this->generator->generate($pdfId, $force);

            if ($result) {
                wp_send_json_success([
                    'pdf_id' => $pdfId,
                    'thumbnail_id' => $result,
                    'thumbnail_url' => $this->generator->getThumbnailUrl($pdfId, 'thumbnail'),
                    'message' => __('Thumbnail generated.', 'rapls-pdf-image-creator'),
                ]);
            } else {
                wp_send_json_error([
                    'pdf_id' => $pdfId,
                    'message' => __('Failed to generate thumbnail.', 'rapls-pdf-image-creator'),
                ]);
            }
        } catch (\Throwable $e) {
            // Log error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Rapls PDF Image Creator: ' . $e->getMessage());
            }
            wp_send_json_error([
                'message' => __('An error occurred while generating thumbnail.', 'rapls-pdf-image-creator'),
            ]);
        }
    }

    /**
     * AJAX: Get bulk status, one page at a time
     *
     * Counts carried by the browser, as for the scan. Without `after` the
     * first page is counted, so a caller that asks once gets the totals of
     * that page and `done` says whether there is more.
     */
    public function ajaxStatus(): void
    {
        try {
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            // As the Settings page Bulk Generate lives on. upload_files let
            // any author list every PDF and regenerate PDFs that are not
            // theirs, by posting straight here (R55-01).
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
                return;
            }

            $page = $this->statsPage(isset($_POST['after']) ? absint($_POST['after']) : 0);
            $total = self::carried('total') + $page['total'];
            $with = self::carried('with_thumbnail') + $page['with_thumbnail'];

            wp_send_json_success([
                'total' => $total,
                'with_thumbnail' => $with,
                'without_thumbnail' => $total - $with,
                'after' => $page['after'],
                'done' => $page['done'],
            ]);
        } catch (\Throwable $e) {
            // Log error for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Rapls PDF Image Creator: ' . $e->getMessage());
            }
            wp_send_json_error([
                'message' => __('An error occurred while fetching status.', 'rapls-pdf-image-creator'),
            ]);
        }
    }

    /** A count the browser carried from the pages before this one; 0 when it sent none. */
    private static function carried(string $name): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller
        return isset($_POST[$name]) ? absint($_POST[$name]) : 0;
    }

    /**
     * Count PDF attachments straight from the database.
     *
     * WP_Query runs through pre_get_posts, where any plugin on the site can
     * narrow it, and a scan that finds nothing cannot tell you whether the
     * library is empty or whether something filtered the answer away. This
     * counts the rows, so the two can be told apart.
     *
     * @return array{total: int, inherit: int, statuses: string}
     */
    private function countPdfRows(): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately bypassing WP_Query; that is the point
        $rows = $wpdb->get_results(
            "SELECT post_status, COUNT(*) AS n
               FROM {$wpdb->posts}
              WHERE post_type = 'attachment'
                AND post_mime_type = 'application/pdf'
           GROUP BY post_status"
        );
        // phpcs:enable

        $total = 0;
        $inherit = 0;
        $parts = [];

        foreach ((array) $rows as $row) {
            $n = (int) $row->n;
            $total += $n;

            if ('inherit' === $row->post_status) {
                $inherit = $n;
            }

            $parts[] = $row->post_status . '=' . $n;
        }

        return [
            'total' => $total,
            'inherit' => $inherit,
            'statuses' => implode(', ', $parts),
        ];
    }

    /**
     * The IDs of the next page of PDFs after a cursor, in ID order
     *
     * Through WP_Query, as before, so the scan sees what the Media Library
     * sees and countPdfRows() can still tell a filtered query from an empty
     * library. Their meta is read in one query per page, not one per PDF.
     *
     * @return array<int, int>
     */
    private function idsAfter(int $after, int $limit): array
    {
        global $wpdb;

        $where = static function (string $sql) use ($wpdb, $after): string {
            return $sql . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after);
        };

        add_filter('posts_where', $where);

        try {
            $query = new \WP_Query([
                'post_type' => 'attachment',
                'post_mime_type' => 'application/pdf',
                'post_status' => 'inherit',
                'posts_per_page' => $limit,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => false,
                // Marks this as the plugin asking, so MediaLibrary's
                // pre_get_posts filter leaves it alone.
                'rapls_pic_internal' => true,
            ]);
        } finally {
            remove_filter('posts_where', $where);
        }

        $ids = array_map('intval', (array) $query->posts);

        if ([] !== $ids && function_exists('update_postmeta_cache')) {
            update_postmeta_cache($ids);
        }

        return $ids;
    }

    /**
     * Forget what one page put in the object cache
     *
     * Without a persistent object cache it is this request's memory, and a
     * scan of the whole library kept every PDF's meta in it. With one, it is
     * left alone: emptying it would only cost the next page view.
     *
     * @param array<int, int> $ids
     */
    private static function release(array $ids): void
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return;
        }

        foreach ($ids as $id) {
            wp_cache_delete($id, 'post_meta');
            wp_cache_delete($id, 'posts');
        }
    }

    /**
     * One page of the library counted: how many PDFs, how many with a thumbnail
     *
     * @return array{total: int, with_thumbnail: int, after: int, done: bool}
     */
    public function statsPage(int $after): array
    {
        $ids = $this->idsAfter($after, self::PAGE);
        $with = 0;

        foreach ($ids as $id) {
            if ($this->generator->hasThumbnail($id)) {
                ++$with;
            }
        }

        self::release($ids);

        return [
            'total' => count($ids),
            'with_thumbnail' => $with,
            'after' => [] !== $ids ? (int) end($ids) : $after,
            'done' => count($ids) < self::PAGE,
        ];
    }

    /**
     * The next PDF after a cursor that needs drawing
     *
     * Every PDF with $force; otherwise one without a thumbnail. Looks through
     * SEARCH_PAGES pages at most.
     *
     * @return array{pdf_id: int|null, after: int, done: bool}
     */
    public function nextToGenerate(int $after, bool $force): array
    {
        for ($i = 0; $i < self::SEARCH_PAGES; ++$i) {
            $ids = $this->idsAfter($after, self::PAGE);

            foreach ($ids as $id) {
                if ($force || !$this->generator->hasThumbnail($id)) {
                    self::release($ids);

                    return ['pdf_id' => $id, 'after' => $id, 'done' => false];
                }
            }

            self::release($ids);

            if (count($ids) < self::PAGE) {
                return ['pdf_id' => null, 'after' => [] !== $ids ? (int) end($ids) : $after, 'done' => true];
            }

            $after = (int) end($ids);
        }

        return ['pdf_id' => null, 'after' => $after, 'done' => false];
    }

    /**
     * Get statistics for the whole library
     *
     * Page by page, as statsPage() counts them, for a caller in PHP. The
     * screens ask page by page themselves (ajaxStatus()).
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $total = 0;
        $withThumbnail = 0;
        $after = 0;

        do {
            $page = $this->statsPage($after);
            $total += $page['total'];
            $withThumbnail += $page['with_thumbnail'];
            $after = $page['after'];
        } while (!$page['done']);

        return [
            'total' => $total,
            'with_thumbnail' => $withThumbnail,
            'without_thumbnail' => $total - $withThumbnail,
        ];
    }
}
