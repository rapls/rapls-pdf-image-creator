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
     * Settings instance
     */
    private Settings $settings;

    /**
     * Constructor
     *
     * @param Generator $generator Thumbnail generator
     * @param Settings $settings Settings manager
     */
    public function __construct(Generator $generator, Settings $settings)
    {
        $this->generator = $generator;
        $this->settings = $settings;
    }

    /**
     * Initialize AJAX handlers
     */
    public function init(): void
    {
        add_action('wp_ajax_rapls_pic_bulk_scan', [$this, 'ajaxScan']);
        add_action('wp_ajax_rapls_pic_bulk_generate', [$this, 'ajaxGenerate']);
        add_action('wp_ajax_rapls_pic_bulk_status', [$this, 'ajaxStatus']);
    }

    /**
     * AJAX: Scan for PDFs
     */
    public function ajaxScan(): void
    {
        try {
            // Verify nonce - use false to not die on failure
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')]);
                return;
            }

            $includeExisting = !empty($_POST['include_existing']);
            $pdfs = $this->getPDFs($includeExisting);
            $stats = $this->getStats();

            // "PDFs found: 0" on its own leaves the reader with no idea
            // whether the library is empty, whether everything is already
            // done, or whether something is broken. Those are three different
            // situations and only one of them is a problem.
            $rows = $this->countPdfRows();
            $note = '';

            if (0 === count($pdfs)) {
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
                'total' => count($pdfs),
                'total_pdfs' => $stats['total'],
                'rows' => $rows['total'],
                'statuses' => $rows['statuses'],
                'note' => $note,
                'pdfs' => $pdfs,
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
     * AJAX: Generate single thumbnail
     */
    public function ajaxGenerate(): void
    {
        try {
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')]);
                return;
            }

            $pdfId = isset($_POST['pdf_id']) ? absint($_POST['pdf_id']) : 0;
            $force = !empty($_POST['force']);

            if (!$pdfId) {
                wp_send_json_error(['message' => __('Invalid PDF ID.', 'rapls-pdf-image-creator')]);
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
     * AJAX: Get bulk status
     */
    public function ajaxStatus(): void
    {
        try {
            if (!check_ajax_referer('rapls_pic_admin', 'nonce', false)) {
                wp_send_json_error(['message' => __('Security check failed.', 'rapls-pdf-image-creator')]);
                return;
            }

            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')]);
                return;
            }

            $stats = $this->getStats();
            wp_send_json_success($stats);
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
     * Get all PDFs
     *
     * @param bool $includeExisting Include PDFs that already have thumbnails
     * @return array<array<string, mixed>>
     */
    public function getPDFs(bool $includeExisting = false): array
    {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            // Marks this as the plugin asking, so MediaLibrary's pre_get_posts
            // filter leaves it alone.
            'rapls_pic_internal' => true,
        ];

        $query = new \WP_Query($args);
        $pdfIds = $query->posts;

        $pdfs = [];
        foreach ($pdfIds as $pdfId) {
            $pdfId = (int) $pdfId;
            $hasThumbnail = $this->generator->hasThumbnail($pdfId);

            if (!$includeExisting && $hasThumbnail) {
                continue;
            }

            $pdfs[] = [
                'id' => $pdfId,
                'title' => get_the_title($pdfId),
                'filename' => basename(get_attached_file($pdfId) ?: ''),
                'has_thumbnail' => $hasThumbnail,
            ];
        }

        return $pdfs;
    }

    /**
     * Get statistics
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'rapls_pic_internal' => true,
        ];

        $query = new \WP_Query($args);
        $pdfIds = $query->posts;

        $total = count($pdfIds);
        $withThumbnail = 0;

        foreach ($pdfIds as $pdfId) {
            if ($this->generator->hasThumbnail((int) $pdfId)) {
                $withThumbnail++;
            }
        }

        return [
            'total' => $total,
            'with_thumbnail' => $withThumbnail,
            'without_thumbnail' => $total - $withThumbnail,
        ];
    }
}
