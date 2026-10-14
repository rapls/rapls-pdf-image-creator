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
     * The images idsAfter() put in the object cache for the page it read
     *
     * @var array<int, int>
     */
    private array $primed = [];

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
                    $note = __('There are no PDF files in the Media Library. Uploading a PDF over FTP or SSH does not add it — it has to go through Media > Add Media File.', 'rapls-pdf-image-creator');
                } elseif (0 === $stats['total'] && $rows['trash'] === $rows['total']) {
                    // Every one is in the trash (MEDIA_TRASH): the scan
                    // rightly leaves them out, and blaming another plugin for
                    // it sent people looking for one (review after 1.4.31).
                    $note = sprintf(
                        /* translators: %d: number of PDFs in the trash */
                        _n(
                            'The only PDF in the Media Library is in the trash (%d). Restore it to generate its thumbnail.',
                            'All the PDFs in the Media Library are in the trash (%d). Restore them to generate their thumbnails.',
                            $rows['total'],
                            'rapls-pdf-image-creator'
                        ),
                        $rows['total']
                    );
                } elseif (0 === $stats['total'] && 0 === $rows['inherit']) {
                    // None of them is an ordinary Media Library item: private,
                    // in the trash, or given a status by another plugin. The
                    // scan takes "inherit" only, so blaming another plugin's
                    // query was wrong here too (review of 1.4.32), and
                    // "private or trashed" did not cover the last (review
                    // after 1.4.32).
                    $note = sprintf(
                        /* translators: %s: post statuses and counts, e.g. "private=2, trash=1" */
                        __('No PDF here is an ordinary Media Library item (%s). Bulk Generate handles those only; a PDF with any other status — private, in the trash, or one another plugin gave it — is left out until it is an ordinary item again.', 'rapls-pdf-image-creator'),
                        $rows['statuses']
                    );
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
                // The old thumbnail kept, a post showing it: said, as the
                // Media Library's notice says it (review after 1.4.37).
                $kept = $this->generator->outcomeFor($pdfId)['kept_previous'] ?? null;
                wp_send_json_success([
                    'pdf_id' => $pdfId,
                    'thumbnail_id' => $result,
                    'thumbnail_url' => $this->generator->getThumbnailUrl($pdfId, 'thumbnail'),
                    'message' => null !== $kept
                        /* translators: 1: the first sentence, 2: the next. */
                        ? sprintf(__('%1$s %2$s', 'rapls-pdf-image-creator'), __('Thumbnail generated.', 'rapls-pdf-image-creator'), Admin::keptPreviousNote($this->generator->keptReasonFor($pdfId) ?? Generator::KEPT_SHOWN))
                        : __('Thumbnail generated.', 'rapls-pdf-image-creator'),
                    'kept_previous' => $kept,
                ]);
            } else {
                // With the reason, as the Media Library's notice gives it:
                // every failure said only "Failed to generate thumbnail."
                // (review after 1.4.33).
                $outcome = $this->generator->outcomeFor($pdfId);
                $reason = Admin::failureReason((string) ($outcome['code'] ?? ''));

                wp_send_json_error([
                    'pdf_id' => $pdfId,
                    'message' => '' !== $reason
                        ? $reason
                        : __('Failed to generate thumbnail.', 'rapls-pdf-image-creator'),
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
     * @return array{total: int, inherit: int, trash: int, statuses: string}
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
        $trash = 0;
        $parts = [];

        foreach ((array) $rows as $row) {
            $n = (int) $row->n;
            $total += $n;

            if ('inherit' === $row->post_status) {
                $inherit = $n;
            }

            if ('trash' === $row->post_status) {
                $trash = $n;
            }

            $parts[] = $row->post_status . '=' . $n;
        }

        return [
            'total' => $total,
            'inherit' => $inherit,
            'trash' => $trash,
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

        // This query only: another plugin's query run while it is being
        // built -- from pre_get_posts, say -- went through the same filter
        // and lost its first posts (review after 1.4.37). The query carries
        // a token, and the filter asks for it.
        $token = 'rapls-pic-' . bin2hex(random_bytes(6));
        $where = static function ($sql, $query = null) use ($wpdb, $after, $token) {
            if (!is_string($sql) || !is_object($query) || !method_exists($query, 'get') || $token !== $query->get('rapls_pic_after_token')) {
                return $sql;
            }

            return $sql . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after);
        };

        add_filter('posts_where', $where, 10, 2);

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
                'rapls_pic_after_token' => $token,
            ]);
        } finally {
            remove_filter('posts_where', $where, 10);
        }

        $ids = array_map('intval', (array) $query->posts);
        $this->primed = [];

        if ([] !== $ids && function_exists('update_postmeta_cache')) {
            update_postmeta_cache($ids);
        }

        // And the images their meta names, in one query: hasThumbnail() asks
        // whether each still exists, and that was a query per PDF (review
        // after 1.4.33).
        if ([] !== $ids && function_exists('_prime_post_caches')) {
            foreach ($ids as $id) {
                foreach ([Generator::THUMBNAIL_META_KEY, '_thumbnail_id'] as $key) {
                    $named = (int) get_post_meta($id, $key, true);
                    if ($named > 0) {
                        $this->primed[$named] = $named;
                    }
                }
            }

            $this->primed = array_values($this->primed);
            if ([] !== $this->primed) {
                _prime_post_caches($this->primed, false, false);
            }
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
    private function release(array $ids): void
    {
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            return;
        }

        foreach ($ids as $id) {
            wp_cache_delete($id, 'post_meta');
            wp_cache_delete($id, 'posts');
        }

        // The images looked up with them, too.
        foreach ($this->primed as $id) {
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

        $this->release($ids);

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
     * Every PDF with $force; otherwise one without a thumbnail, looking
     * through SEARCH_PAGES pages at most.
     *
     * @return array{pdf_id: int|null, after: int, done: bool}
     */
    public function nextToGenerate(int $after, bool $force): array
    {
        // With $force the first PDF is the answer: one row, not a page of
        // 200 with their meta and images read, every PDF of the run (review
        // of 1.4.34).
        if ($force) {
            $ids = $this->idsAfter($after, 1);
            // Its own entry is kept: ajaxNext() reads its file name next,
            // which released was one more query for every PDF of the run
            // (review of 1.4.35). Only the images looked up go.
            $this->release([]);

            return [] === $ids
                ? ['pdf_id' => null, 'after' => $after, 'done' => true]
                : ['pdf_id' => $ids[0], 'after' => $ids[0], 'done' => false];
        }

        for ($i = 0; $i < self::SEARCH_PAGES; ++$i) {
            $ids = $this->idsAfter($after, self::PAGE);

            foreach ($ids as $id) {
                if (!$this->generator->hasThumbnail($id)) {
                    // All but the answer, whose file name is read next.
                    $this->release(array_values(array_diff($ids, [$id])));

                    return ['pdf_id' => $id, 'after' => $id, 'done' => false];
                }
            }

            $this->release($ids);

            if (count($ids) < self::PAGE) {
                return ['pdf_id' => null, 'after' => [] !== $ids ? (int) end($ids) : $after, 'done' => true];
            }

            $after = (int) end($ids);
        }

        return ['pdf_id' => null, 'after' => $after, 'done' => false];
    }
}
