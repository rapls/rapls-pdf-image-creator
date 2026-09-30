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
use Rapls\PDFImageCreator\FailureCode;

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
     * Option recording why the last generation attempt had no engine
     *
     * Only ever holds a status code from getAvailabilityStatus(), so it is
     * safe to display and cheap to read on an admin page.
     */
    public const UNAVAILABLE_OPTION = 'rapls_pic_engine_unavailable';

    /**
     * Option holding the last generation failure, for the Status tab
     *
     * Holds a code, the message, which attachment it was and when. Overwritten
     * on each failure and deleted on the next success, so it answers "is
     * anything wrong right now" rather than accumulating a log.
     */
    public const LAST_FAILURE_OPTION = 'rapls_pic_last_failure';

    /**
     * Explain whether thumbnails can be generated on this server, and why not
     *
     * @return array{code: string, label: string, summary: string, action: string, detail: string}
     */
    public function getAvailabilityStatus(): array
    {
        foreach ($this->engines as $engine) {
            $status = $engine->getAvailabilityStatus();
            if ('ok' === $status['code']) {
                return $status;
            }
        }

        // No engine said yes. Report the first one's reason; with a single
        // engine that is the whole story, and with more it is still the most
        // useful thing to show.
        foreach ($this->engines as $engine) {
            return $engine->getAvailabilityStatus();
        }

        return [
            'code' => 'no_engine',
            'label' => __('No conversion engine', 'rapls-pdf-image-creator'),
            'summary' => __('No PDF conversion engine is registered.', 'rapls-pdf-image-creator'),
            'action' => '',
            'detail' => '',
        ];
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
            return $this->fail($pdfId, FailureCode::NOT_A_PDF, __('No such attachment.', 'rapls-pdf-image-creator'));
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($pdfId);
        if ($mimeType !== 'application/pdf') {
            return $this->fail($pdfId, FailureCode::NOT_A_PDF, __('Not a PDF file.', 'rapls-pdf-image-creator'));
        }

        // Check if thumbnail already exists
        if (!$force && $this->hasThumbnail($pdfId)) {
            return $this->getThumbnailId($pdfId);
        }

        // The thumbnail being replaced, let go of only once the new one is in
        // place. It used to be deleted here, before the PDF was even looked
        // at, and any failure after that -- a missing file, no engine, a
        // render error -- left the PDF with no thumbnail at all (R55-03).
        $previousId = $force ? $this->getThumbnailId($pdfId) : null;
        $replacing = null !== $previousId;

        // Get PDF file path
        $pdfPath = get_attached_file($pdfId);
        if (!$pdfPath || !file_exists($pdfPath)) {
            return $this->fail(
                $pdfId,
                FailureCode::SOURCE_MISSING,
                __('The PDF file is missing from the uploads folder.', 'rapls-pdf-image-creator')
            );
        }

        // Get available engine
        $engine = $this->getAvailableEngine();
        if (!$engine) {
            // This used to return in silence, which is the worst way to fail:
            // the thumbnail simply never appears and nothing anywhere says
            // why. Leave a trace, and record it for the admin notice.
            $status = $this->getAvailabilityStatus();

            // Bulk Generate calls this in a loop; only write when the answer
            // actually changed.
            if (get_option(self::UNAVAILABLE_OPTION) !== $status['code']) {
                update_option(self::UNAVAILABLE_OPTION, $status['code'], false);
            }

            return $this->fail($pdfId, $status['code'], $status['summary']);
        }

        // Prepare output path
        $uploadDir = wp_upload_dir();
        $pdfDir = dirname($pdfPath);
        $pdfBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
        $extension = $this->settings->getFileExtension();

        $outputFilename = $pdfBasename . '-pdf-thumbnail.' . $extension;

        // A replacement needs a name of its own.
        //
        // Deleting the old thumbnail frees its filename, so the loop below
        // hands the new file the name the old one had, and every browser that
        // cached the old image goes on showing it. That is worst for exactly
        // the people who need it least: someone regenerating a thumbnail is
        // usually replacing one that came out wrong. Measured on a live site
        // -- a blank thumbnail, correctly regenerated after the server was
        // fixed, still looked blank until the file was given a different name.
        //
        // Only on a forced regeneration, so a first run keeps the plain name.
        if ($replacing) {
            $outputFilename = $pdfBasename . '-pdf-thumbnail-' . dechex(time()) . '.' . $extension;
        }

        $outputPath = $pdfDir . '/' . $outputFilename;

        // Ensure unique filename
        $counter = 1;
        $stem = pathinfo($outputFilename, PATHINFO_FILENAME);
        while (file_exists($outputPath)) {
            $outputFilename = $stem . '-' . $counter . '.' . $extension;
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

        // Build conversion options.
        //
        // attachment_id and source_path are context, not settings: they are not
        // filtered and the engine does not read them. They are there so that a
        // listener on rapls_pdf_image_creator_before_resize can tell which
        // attachment it is looking at -- the engine signature is (path, path,
        // options) and carries no attachment anywhere else.
        $options = [
            'attachment_id' => $pdfId,
            'source_path' => $pdfPath,
            'page' => apply_filters('rapls_pdf_image_creator_thumbnail_page', $this->settings->getPage(), $pdfId),
            'max_width' => apply_filters('rapls_pdf_image_creator_thumbnail_max_width', $this->settings->getMaxWidth(), $pdfId),
            'max_height' => apply_filters('rapls_pdf_image_creator_thumbnail_max_height', $this->settings->getMaxHeight(), $pdfId),
            'resolution' => apply_filters('rapls_pdf_image_creator_thumbnail_resolution', $this->settings->getResolution(), $pdfId),
            'quality' => apply_filters('rapls_pdf_image_creator_thumbnail_quality', $this->settings->getQuality(), $pdfId),
            'format' => apply_filters('rapls_pdf_image_creator_thumbnail_format', $this->settings->getFormat(), $pdfId),
            'bgcolor' => apply_filters('rapls_pdf_image_creator_thumbnail_bgcolor', $this->settings->getBgColor(), $pdfId),
        ];

        // Convert PDF to image
        $result = $engine->convert($pdfPath, $outputPath, $options);

        if (!$result->isSuccess()) {
            return $this->fail(
                $pdfId,
                $result->getCode() ?: FailureCode::RENDER_ERROR,
                (string) $result->getError(),
                $result
            );
        }

        // Create attachment for thumbnail
        $thumbnailId = $this->createThumbnailAttachment($pdfId, $outputPath, $outputFilename);

        if (!$thumbnailId) {
            // Clean up file
            wp_delete_file($outputPath);

            return $this->fail(
                $pdfId,
                FailureCode::WRITE_FAILED,
                __('The image was rendered but could not be added to the Media Library.', 'rapls-pdf-image-creator')
            );
        }

        // Store thumbnail ID in PDF meta, and read it back: not stored, the
        // new image is nobody's, and the old one is still the PDF's.
        update_post_meta($pdfId, self::THUMBNAIL_META_KEY, $thumbnailId);

        if ((int) get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true) !== $thumbnailId) {
            wp_delete_attachment($thumbnailId, true);

            return $this->fail(
                $pdfId,
                FailureCode::WRITE_FAILED,
                __('The image was rendered but could not be recorded as this PDF\'s thumbnail.', 'rapls-pdf-image-creator')
            );
        }

        // Set as featured image if enabled
        if ($this->settings->shouldSetFeatured()) {
            update_post_meta($pdfId, '_thumbnail_id', $thumbnailId);
        }

        // Only now that the new one is the PDF's. An image this PDF did not
        // make -- a featured image chosen by hand, which getThumbnailId()
        // falls back to -- is neither deleted nor unlinked: the new thumbnail
        // is found first, and the choice was not this plugin's (R55-02).
        if (null !== $previousId && $previousId !== $thumbnailId && $this->isOwnThumbnail($previousId, $pdfId)) {
            $this->releaseThumbnail($pdfId, $previousId);
        }

        /**
         * Action after generating thumbnail
         *
         * @param int $thumbnailId Thumbnail attachment ID
         * @param int $pdfId PDF attachment ID
         * @param ConversionResult $result Conversion result
         */
        // Something worked. Whatever the Status tab was complaining about is
        // no longer true.
        if (false !== get_option(self::LAST_FAILURE_OPTION, false)) {
            delete_option(self::LAST_FAILURE_OPTION);
        }

        do_action('rapls_pdf_image_creator_after_generate', $thumbnailId, $pdfId, $result);

        return $thumbnailId;
    }

    /**
     * Record a failure, announce it, and return null.
     *
     * Every path out of generate() that does not produce a thumbnail comes
     * through here. Before 1.4.0 most of them returned null in silence, which
     * meant the thumbnail simply never appeared and nothing anywhere said why
     * -- the single most common support question this plugin gets.
     *
     * @param int                   $pdfId   Attachment the attempt was for.
     * @param string                $code    See FailureCode.
     * @param string                $message Sentence for a person, already translated.
     * @param ConversionResult|null $result  The engine's result, when there was one.
     * @return null Always. Callers return this straight back.
     */
    private function fail(int $pdfId, string $code, string $message, ?ConversionResult $result = null)
    {
        // NOT_A_PDF is not recorded. It means someone asked for a thumbnail of
        // something that was never a PDF -- a template call with the wrong ID,
        // usually -- and putting that on the Status tab would be alarming a
        // site owner about their theme's bug.
        if (FailureCode::NOT_A_PDF !== $code) {
            update_option(
                self::LAST_FAILURE_OPTION,
                [
                    'code' => $code,
                    'message' => $message,
                    'pdf_id' => $pdfId,
                    'time' => time(),
                ],
                false
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Rapls PDF Image Creator: generation failed for #%d (%s) - %s',
                $pdfId,
                $code,
                $message
            ));
        }

        /**
         * Action when generation fails.
         *
         * @since 1.0.0
         * @since 1.4.0 The $code and $result arguments were added, and the
         *              action fires on every failure rather than only on a
         *              conversion error.
         *
         * @param string                $message Human-readable error.
         * @param int                   $pdfId   PDF attachment ID.
         * @param string                $code    Machine-readable reason. See FailureCode.
         * @param ConversionResult|null $result  Engine result, or null when the
         *                                       failure happened before the engine ran.
         */
        do_action('rapls_pdf_image_creator_generation_failed', $message, $pdfId, $code, $result);

        return null;
    }

    /**
     * What went wrong the last time a thumbnail was attempted
     *
     * @return array{code: string, message: string, pdf_id: int, time: int}|null
     */
    public function getLastFailure(): ?array
    {
        $stored = get_option(self::LAST_FAILURE_OPTION, false);

        if (!is_array($stored) || empty($stored['code'])) {
            return null;
        }

        return [
            'code' => (string) $stored['code'],
            'message' => (string) ($stored['message'] ?? ''),
            'pdf_id' => (int) ($stored['pdf_id'] ?? 0),
            'time' => (int) ($stored['time'] ?? 0),
        ];
    }

    /**
     * Whether generate() made this image from this PDF
     *
     * createAttachment() tags every thumbnail with both, and has since the
     * first release.
     */
    public function isOwnThumbnail(int $thumbnailId, int $pdfId): bool
    {
        return '' !== (string) get_post_meta($thumbnailId, '_rapls_pic_is_thumbnail', true)
            && (int) get_post_meta($thumbnailId, '_rapls_pic_source_pdf', true) === $pdfId;
    }

    /**
     * Whether anything but this PDF names this image as its thumbnail
     *
     * This plugin's own meta on another attachment, or `_thumbnail_id` on any
     * post. When the database does not answer, the answer is yes: an image
     * kept is recoverable, an image deleted is not.
     */
    private function usedElsewhere(int $thumbnailId, int $pdfId): bool
    {
        global $wpdb;

        // No database to ask is the same answer as a database that did not
        // answer: the image is kept.
        if (!is_object($wpdb) || !isset($wpdb->postmeta) || !method_exists($wpdb, 'get_var')) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value = %s AND post_id <> %d LIMIT 1",
            self::THUMBNAIL_META_KEY,
            '_thumbnail_id',
            (string) $thumbnailId,
            $pdfId
        ));

        return null !== $found || '' !== (string) $wpdb->last_error;
    }

    /**
     * Delete thumbnail for a PDF
     *
     * Only an image generated from this PDF is deleted. getThumbnailId() also
     * answers with `_thumbnail_id`, which another plugin -- or a site moved
     * over from one -- can point at any image in the library, one embedded
     * in posts among them; and a translation plugin can copy this plugin's
     * own meta from another PDF, so it names that PDF's thumbnail. Both were
     * deleted for good, file and all, by a forced regeneration or by
     * deleting the PDF. They are now only unlinked from this PDF.
     *
     * @param int $pdfId PDF attachment ID
     * @return bool Whether an image was deleted
     */
    public function deleteThumbnail(int $pdfId): bool
    {
        $thumbnailId = $this->getThumbnailId($pdfId);

        if (!$thumbnailId) {
            return false;
        }

        return $this->releaseThumbnail($pdfId, $thumbnailId);
    }

    /**
     * Let this PDF go of one image, and delete it if nothing else needs it
     *
     * Each key is removed only while it still names this image. The PDF's
     * `_thumbnail_id` could name another image altogether -- a featured image
     * chosen by hand, with "Set as featured image" off -- and was removed
     * whatever it named (R55-02).
     *
     * @return bool Whether the image was deleted
     */
    private function releaseThumbnail(int $pdfId, int $thumbnailId): bool
    {
        $result = false;

        // Made from this PDF is not enough when something else shows it too:
        // a translation's copy of this PDF that shares its thumbnail, or a
        // post that took it as its featured image. Deleting it left them
        // pointing at an image that was gone; only this PDF lets go of it.
        if ($this->isOwnThumbnail($thumbnailId, $pdfId) && !$this->usedElsewhere($thumbnailId, $pdfId)) {
            // Delete the attachment (this also deletes the file)
            $result = wp_delete_attachment($thumbnailId, true);
        }

        // Clean up meta
        foreach ([self::THUMBNAIL_META_KEY, '_thumbnail_id'] as $key) {
            if ((int) get_post_meta($pdfId, $key, true) === $thumbnailId) {
                delete_post_meta($pdfId, $key);
            }
        }

        return $result !== false && $result !== null;
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
            'availability' => $this->getAvailabilityStatus(),
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
