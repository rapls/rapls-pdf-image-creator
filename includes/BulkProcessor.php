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

            wp_send_json_success([
                'total' => count($pdfs),
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
