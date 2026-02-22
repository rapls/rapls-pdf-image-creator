<?php
/**
 * Thumbnail Generator
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

use Rapls\PDFImageCreator\Engine\EngineInterface;
use Rapls\PDFImageCreator\Engine\ImagickEngine;
use Rapls\PDFImageCreator\Engine\ConversionResult;

/**
 * Generates thumbnail images from PDF files
 */
final class Generator
{
    /**
     * Meta key for storing thumbnail ID
     */
    public const THUMBNAIL_META_KEY = '_rapls_pic_thumbnail_id';

    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Available engines
     *
     * @var EngineInterface[]
     */
    private array $engines = [];

    /**
     * Constructor
     *
     * @param Settings $settings Settings manager
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->initEngines();
    }

    /**
     * Initialize available engines
     */
    private function initEngines(): void
    {
        $this->engines = [
            'imagick' => new ImagickEngine(),
        ];

        /**
         * Filter available engines
         *
         * @param EngineInterface[] $engines Available engines
         */
        $this->engines = apply_filters('rapls_pdf_image_creator_engines', $this->engines);
    }

    /**
     * Get available engines
     *
     * @return EngineInterface[]
     */
    public function getEngines(): array
    {
        return $this->engines;
    }

    /**
     * Get available engine for conversion
     *
     * @return EngineInterface|null
     */
    public function getAvailableEngine(): ?EngineInterface
    {
        // Try each engine (currently only Imagick)
        foreach ($this->engines as $engine) {
            if ($engine->isAvailable()) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * Check if PDF has a thumbnail
     *
     * @param int $pdfId PDF attachment ID
     * @return bool
     */
    public function hasThumbnail(int $pdfId): bool
    {
        return $this->getThumbnailId($pdfId) !== null;
    }

    /**
     * Get thumbnail ID for a PDF
     *
     * @param int $pdfId PDF attachment ID
     * @return int|null Thumbnail attachment ID or null
     */
    public function getThumbnailId(int $pdfId): ?int
    {
        // First check our custom meta
        $thumbnailId = get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true);
        if ($thumbnailId) {
            // Verify the thumbnail still exists
            if (get_post($thumbnailId)) {
                return (int) $thumbnailId;
            }
            // Clean up stale meta
            delete_post_meta($pdfId, self::THUMBNAIL_META_KEY);
        }

        // Fall back to _thumbnail_id (WordPress standard)
        $thumbnailId = get_post_meta($pdfId, '_thumbnail_id', true);
        if ($thumbnailId && get_post($thumbnailId)) {
            return (int) $thumbnailId;
        }

        return null;
    }

    /**
     * Get thumbnail URL
     *
     * @param int $pdfId PDF attachment ID
     * @param string $size Image size
     * @return string|null Thumbnail URL or null
     */
    public function getThumbnailUrl(int $pdfId, string $size = 'thumbnail'): ?string
    {
        $thumbnailId = $this->getThumbnailId($pdfId);
        if (!$thumbnailId) {
            return null;
        }

        $url = wp_get_attachment_image_url($thumbnailId, $size);
        return $url ?: null;
    }

    /**
     * Get thumbnail image HTML
     *
     * @param int $pdfId PDF attachment ID
     * @param string $size Image size
     * @param array<string, mixed> $attr Image attributes
     * @return string Image HTML or empty string
     */
    public function getThumbnailImage(int $pdfId, string $size = 'thumbnail', array $attr = []): string
    {
        $thumbnailId = $this->getThumbnailId($pdfId);
        if (!$thumbnailId) {
            return '';
        }

        $defaultAttr = [
            'class' => 'pic-thumbnail',
            'alt' => get_the_title($pdfId),
        ];

        $attr = array_merge($defaultAttr, $attr);

        /**
         * Filter thumbnail image attributes
         *
         * @param array $attr Image attributes
         * @param int $pdfId PDF attachment ID
         * @param int $thumbnailId Thumbnail attachment ID
         */
        $attr = apply_filters('rapls_pdf_image_creator_thumbnail_image_attributes', $attr, $pdfId, $thumbnailId);

        return wp_get_attachment_image($thumbnailId, $size, false, $attr);
    }

    /**
     * Generate thumbnail for a PDF
     *
     * @param int $pdfId PDF attachment ID
     * @param bool $force Force regeneration
     * @return int|null Thumbnail attachment ID or null on failure
     */
    public function generate(int $pdfId, bool $force = false): ?int
    {
        // Check if PDF exists
        $pdf = get_post($pdfId);
        if (!$pdf || $pdf->post_type !== 'attachment') {
            return null;
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($pdfId);
        if ($mimeType !== 'application/pdf') {
            return null;
        }

        // Check if thumbnail already exists
        if (!$force && $this->hasThumbnail($pdfId)) {
            return $this->getThumbnailId($pdfId);
        }

        // Delete existing thumbnail if forcing
        if ($force) {
            $this->deleteThumbnail($pdfId);
        }

        // Get PDF file path
        $pdfPath = get_attached_file($pdfId);
        if (!$pdfPath || !file_exists($pdfPath)) {
            return null;
        }

        // Get available engine
        $engine = $this->getAvailableEngine();
        if (!$engine) {
            return null;
        }

        // Prepare output path
        $uploadDir = wp_upload_dir();
        $pdfDir = dirname($pdfPath);
        $pdfBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
        $extension = $this->settings->getFileExtension();

        $outputFilename = $pdfBasename . '-pdf-thumbnail.' . $extension;
        $outputPath = $pdfDir . '/' . $outputFilename;

        // Ensure unique filename
        $counter = 1;
        while (file_exists($outputPath)) {
            $outputFilename = $pdfBasename . '-pdf-thumbnail-' . $counter . '.' . $extension;
            $outputPath = $pdfDir . '/' . $outputFilename;
            $counter++;
        }

        /**
         * Action before generating thumbnail
         *
         * @param int $pdfId PDF attachment ID
         * @param string $pdfPath PDF file path
         */
        do_action('rapls_pdf_image_creator_before_generate', $pdfId, $pdfPath);

        // Build conversion options
        $options = [
            'page' => apply_filters('rapls_pdf_image_creator_thumbnail_page', $this->settings->getPage(), $pdfId),
            'max_width' => apply_filters('rapls_pdf_image_creator_thumbnail_max_width', $this->settings->getMaxWidth(), $pdfId),
            'max_height' => apply_filters('rapls_pdf_image_creator_thumbnail_max_height', $this->settings->getMaxHeight(), $pdfId),
            'quality' => apply_filters('rapls_pdf_image_creator_thumbnail_quality', $this->settings->getQuality(), $pdfId),
            'format' => apply_filters('rapls_pdf_image_creator_thumbnail_format', $this->settings->getFormat(), $pdfId),
            'bgcolor' => apply_filters('rapls_pdf_image_creator_thumbnail_bgcolor', $this->settings->getBgColor(), $pdfId),
        ];

        // Convert PDF to image
        $result = $engine->convert($pdfPath, $outputPath, $options);

        if (!$result->isSuccess()) {
            /**
             * Action when generation fails
             *
             * @param string $error Error message
             * @param int $pdfId PDF attachment ID
             */
            do_action('rapls_pdf_image_creator_generation_failed', $result->getError(), $pdfId);
            return null;
        }

        // Create attachment for thumbnail
        $thumbnailId = $this->createThumbnailAttachment($pdfId, $outputPath, $outputFilename);

        if (!$thumbnailId) {
            // Clean up file
            wp_delete_file($outputPath);
            return null;
        }

        // Store thumbnail ID in PDF meta
        update_post_meta($pdfId, self::THUMBNAIL_META_KEY, $thumbnailId);

        // Set as featured image if enabled
        if ($this->settings->shouldSetFeatured()) {
            update_post_meta($pdfId, '_thumbnail_id', $thumbnailId);
        }

        /**
         * Action after generating thumbnail
         *
         * @param int $thumbnailId Thumbnail attachment ID
         * @param int $pdfId PDF attachment ID
         * @param ConversionResult $result Conversion result
         */
        do_action('rapls_pdf_image_creator_after_generate', $thumbnailId, $pdfId, $result);

        return $thumbnailId;
    }

    /**
     * Delete thumbnail for a PDF
     *
     * @param int $pdfId PDF attachment ID
     * @return bool Success
     */
    public function deleteThumbnail(int $pdfId): bool
    {
        $thumbnailId = $this->getThumbnailId($pdfId);

        if (!$thumbnailId) {
            return false;
        }

        // Delete the attachment (this also deletes the file)
        $result = wp_delete_attachment($thumbnailId, true);

        // Clean up meta
        delete_post_meta($pdfId, self::THUMBNAIL_META_KEY);
        delete_post_meta($pdfId, '_thumbnail_id');

        return $result !== false;
    }

    /**
     * Create WordPress attachment for thumbnail
     *
     * @param int $pdfId Parent PDF attachment ID
     * @param string $filePath Thumbnail file path
     * @param string $filename Thumbnail filename
     * @return int|null Attachment ID or null on failure
     */
    private function createThumbnailAttachment(int $pdfId, string $filePath, string $filename): ?int
    {
        // Get file info
        $fileType = wp_check_filetype($filename);

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $fileType['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $pdfId,
        ];

        // Insert attachment
        $attachmentId = wp_insert_attachment($attachment, $filePath, $pdfId);

        if (is_wp_error($attachmentId) || !$attachmentId) {
            return null;
        }

        // Generate attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, $metadata);

        // Mark as PDF thumbnail (for filtering in media library)
        update_post_meta($attachmentId, '_rapls_pic_is_thumbnail', '1');
        update_post_meta($attachmentId, '_rapls_pic_source_pdf', $pdfId);

        return $attachmentId;
    }

    /**
     * Check server capabilities
     *
     * @return array<string, mixed>
     */
    public function checkCapabilities(): array
    {
        $capabilities = [
            'engines' => [],
            'available' => false,
        ];

        foreach ($this->engines as $name => $engine) {
            $capabilities['engines'][$name] = [
                'name' => $engine->getDisplayName(),
                'available' => $engine->isAvailable(),
                'requirements' => $engine->getRequirements(),
            ];

            if ($engine->isAvailable()) {
                $capabilities['available'] = true;
            }
        }

        return $capabilities;
    }
}
