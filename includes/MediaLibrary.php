<?php
/**
 * Media Library Integration
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Handles integration with WordPress Media Library
 */
final class MediaLibrary
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
     * Whether the Media Library list's images have been read in one go
     */
    private bool $listPrimed = false;

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
     * Initialize hooks
     */
    public function init(): void
    {
        // Auto-generate thumbnail on upload
        add_action('add_attachment', [$this, 'onAttachmentAdded']);

        // When auto-generate is OFF, suppress WordPress core's built-in
        // PDF preview generation (introduced in WP 4.7) so no thumbnail
        // is created at all on upload.
        add_filter('wp_generate_attachment_metadata', [$this, 'maybeSuppressCorePdfPreview'], 1, 3);

        // Core reads $image_meta['file'/'width'/'height'] without isset()
        // guards in several media functions. PDF metadata has only 'sizes', so
        // rendering a PDF as an image (e.g. as a post-list featured image)
        // raises "Undefined array key" warnings. Populate the missing top-level
        // keys defensively from the 'full' preview.
        add_filter('wp_get_attachment_metadata', [$this, 'ensurePdfMetadataHasImageKeys'], 10, 2);

        // Delete thumbnail when PDF is deleted
        add_action('delete_attachment', [$this, 'onAttachmentDeleted']);

        // Filter attachment image for PDFs
        add_filter('wp_get_attachment_image_src', [$this, 'filterAttachmentImageSrc'], 10, 4);

        // Add PDF thumbnail to attachment display
        add_filter('wp_get_attachment_image', [$this, 'filterAttachmentImage'], 10, 5);

        // Filter attachment data for JavaScript (Media Library & Block Editor)
        add_filter('wp_prepare_attachment_for_js', [$this, 'filterAttachmentForJs'], 10, 3);

        // Filter REST API response for attachments (Block Editor)
        add_filter('rest_prepare_attachment', [$this, 'filterRestAttachment'], 10, 3);

        // Hide generated thumbnails from media library (optional)
        add_action('pre_get_posts', [$this, 'filterMediaLibrary']);

        // Add thumbnail column to media library
        add_filter('manage_media_columns', [$this, 'addMediaColumns']);
        add_action('manage_media_custom_column', [$this, 'renderMediaColumn'], 10, 2);

        // Add regenerate action link
        add_filter('media_row_actions', [$this, 'addRowActions'], 10, 2);

        // Handle regenerate action
        add_action('admin_init', [$this, 'handleRegenerateAction']);

        // AJAX handler for regeneration
        // No AJAX regenerate endpoint: nothing on any screen called it, and
        // the row action above does the same through handleRegenerateAction()
        // (review after 1.4.31).

        // Allow PDFs in image block media selection
        add_filter('ajax_query_attachments_args', [$this, 'allowPdfsInImageSelection']);
        add_filter('posts_where', [$this, 'limitPdfsToThumbnailed'], 10, 2);

        // Add PDF support info to block editor
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
        // And the classic editor's featured image
        add_action('wp_enqueue_media', [$this, 'enqueueClassicFeaturedImage']);

        // Replace PDF icon with thumbnail in media library
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttachmentImageAttributes'], 10, 3);
        add_filter('wp_mime_type_icon', [$this, 'filterMimeTypeIcon'], 10, 3);
    }

    /**
     * Handle new attachment upload
     *
     * Untyped, as the other core hooks here are: do_action() can be called
     * with no argument, or with a string (review of 1.4.35).
     *
     * @param int $attachmentId Attachment ID
     */
    public function onAttachmentAdded($attachmentId = 0): void
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId < 1) {
            return;
        }

        // Check if auto-generate is enabled
        if (!$this->settings->isAutoGenerateEnabled()) {
            return;
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return;
        }

        // Generate thumbnail
        $this->generator->generate($attachmentId);
    }

    /**
     * Suppress WordPress core's PDF preview generation when auto-generate is OFF.
     *
     * WP 4.7+ auto-generates a `-pdf.jpg` preview during
     * wp_generate_attachment_metadata() regardless of plugin settings. When the
     * user has explicitly disabled auto-generation, we must also strip these
     * core-created sizes so no thumbnail appears.
     *
     * Untyped, as the other filters here are: another plugin's filter
     * returning a non-array, or a call with one argument, was a fatal
     * error (review of 1.4.34).
     *
     * @param mixed $metadata     Generated attachment metadata.
     * @param mixed $attachmentId Attachment ID.
     * @param mixed $context      Metadata generation context.
     * @return mixed
     */
    public function maybeSuppressCorePdfPreview($metadata, $attachmentId = 0, $context = '')
    {
        if (!is_array($metadata) || !is_numeric($attachmentId) || $this->settings->isAutoGenerateEnabled()) {
            return $metadata;
        }
        $attachmentId = (int) $attachmentId;

        if (get_post_mime_type($attachmentId) !== 'application/pdf') {
            return $metadata;
        }

        // Remove the core-generated preview file from disk and from the metadata.
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = $this->previewDir($metadata, $attachmentId);
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file']) || $dir === '') {
                    continue;
                }
                $path = $dir . basename((string) $size['file']);
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            $metadata['sizes'] = [];
        }

        return $metadata;
    }

    /**
     * The folder core wrote a PDF's previews into, with a trailing slash.
     *
     * Previews sit next to the PDF. Core's PDF metadata has no `file` key, so
     * reading the folder from it gave the uploads root instead of the year/
     * month folder: the preview stayed on disk while it left the metadata,
     * each regeneration added another (`-pdf-1.jpg`, `-pdf-2.jpg`), and a
     * file of the same name in the uploads root was the one deleted. The
     * attached file is set before metadata is generated, so it is the source;
     * `file` is only a fallback. '' when neither is known -- then nothing is
     * deleted, rather than guessing.
     *
     * @param array<string, mixed> $metadata
     */
    private function previewDir(array $metadata, int $attachmentId): string
    {
        $attached = get_attached_file($attachmentId, true);
        if (is_string($attached) && $attached !== '') {
            return trailingslashit(dirname($attached));
        }

        if (!empty($metadata['file']) && is_string($metadata['file'])) {
            $uploadDir = wp_get_upload_dir();
            return trailingslashit($uploadDir['basedir']) . trailingslashit(dirname($metadata['file']));
        }

        return '';
    }

    /**
     * Ensure PDF attachment metadata exposes top-level image keys.
     *
     * Core generates PDF metadata with `sizes` only — no top-level `file`,
     * `width`, or `height`. Core's media functions read those keys without
     * isset() guards in several places (e.g. `wp_image_add_srcset_and_sizes()`
     * on WP < 7.0, `_wp_get_image_size_from_meta()`, and
     * `wp_calculate_image_srcset()`), so rendering a PDF as an image — for
     * example as a featured image on a post list — raises "Undefined array key"
     * warnings. Populate the missing keys from the stored attached-file path
     * and the `full` preview dimensions.
     *
     * @param mixed $data         Attachment metadata (array for valid attachments).
     * @param int   $attachmentId Attachment ID.
     * @return mixed
     */
    public function ensurePdfMetadataHasImageKeys($data, $attachmentId = 0)
    {
        // Untyped, with a default: this runs on every
        // wp_get_attachment_metadata(), and another plugin applying that
        // filter with one argument, or with '' for the ID, made it a fatal
        // error (review of 1.4.35).
        $attachmentId = (int) $attachmentId;
        if ($attachmentId < 1 || !is_array($data) || empty($data['sizes']) || !is_array($data['sizes'])) {
            return $data;
        }

        if (get_post_mime_type($attachmentId) !== 'application/pdf') {
            return $data;
        }

        if (empty($data['file'])) {
            $attachedFile = get_post_meta($attachmentId, '_wp_attached_file', true);
            if (is_string($attachedFile) && $attachedFile !== '') {
                $data['file'] = $attachedFile;
            }
        }

        $full = $data['sizes']['full'] ?? null;
        if (is_array($full)) {
            if (empty($data['width']) && !empty($full['width'])) {
                $data['width'] = (int) $full['width'];
            }
            if (empty($data['height']) && !empty($full['height'])) {
                $data['height'] = (int) $full['height'];
            }
        }

        return $data;
    }

    /**
     * Handle attachment deletion
     *
     * @param int $attachmentId Attachment ID
     */
    public function onAttachmentDeleted($attachmentId = 0): void
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId < 1) {
            return;
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            $this->forgetDeletedThumbnail($attachmentId);
            return;
        }

        // Only delete thumbnail if hide_generated_images is enabled
        // When unchecked, thumbnails are visible and should be kept as independent images
        if ($this->settings->shouldHideGeneratedImages()) {
            $this->generator->deleteThumbnail($attachmentId, true);
        } else {
            // Just remove the meta link, but keep the image file
            $thumbnailId = $this->generator->getThumbnailId($attachmentId);
            // Only this PDF's own: an image made from another PDF (a
            // translation's copied meta) is still that PDF's thumbnail.
            if ($thumbnailId && $this->generator->isOwnThumbnail($thumbnailId, $attachmentId)) {
                // Another PDF's thumbnail too (a translation's copy): handed
                // to it. Otherwise a regular image now, attached where the
                // PDF was.
                $this->generator->letGoOf($thumbnailId, $attachmentId, true);
            }
            delete_post_meta($attachmentId, Generator::THUMBNAIL_META_KEY);
            delete_post_meta($attachmentId, '_thumbnail_id');
        }

        // An old thumbnail a regeneration kept, still attached to this PDF.
        $this->generator->reparentKeptImages($attachmentId);
    }

    /**
     * A thumbnail deleted on its own: its PDF stops naming it
     *
     * Deleting a generated image from the Media Library left the PDF's
     * `_rapls_pic_thumbnail_id` naming an attachment that was gone, and since
     * getThumbnailId() no longer tidies that up from a page view, it stayed
     * until the next generation (review of 1.4.36). Core removes the
     * `_thumbnail_id` keys itself.
     */
    private function forgetDeletedThumbnail(int $imageId): void
    {
        if ('' === (string) get_post_meta($imageId, '_rapls_pic_is_thumbnail', true)) {
            return;
        }
        $pdfId = (int) get_post_meta($imageId, '_rapls_pic_source_pdf', true);
        if ($pdfId > 0 && (int) get_post_meta($pdfId, Generator::THUMBNAIL_META_KEY, true) === $imageId) {
            delete_post_meta($pdfId, Generator::THUMBNAIL_META_KEY);
        }
    }

    /**
     * Filter attachment image source for PDFs
     *
     * A PDF with a generated thumbnail answers with the thumbnail, even when
     * core found an image of its own. Core's is the `-pdf.jpg` preview it drew
     * at upload through its own Imagick call, without this plugin's blank-page
     * retry — so for exactly the PDFs that retry exists for, core's preview is
     * white. The Edit Media screen asks here (edit_form_image_editor(), size
     * 900x450) and used to show that white preview while the Media Library
     * grid, which goes through filterAttachmentForJs(), showed the thumbnail.
     *
     * Untyped, and cast here: core hands on the ID and $icon as its caller
     * gave them -- '' from an empty post meta, null -- and a typed parameter
     * made that a fatal error on any page, for any attachment, PDF or not
     * (review of 1.4.32). Without this plugin core answers false.
     *
     * @param array|false $image Image data or false
     * @param mixed $attachmentId Attachment ID, as the caller gave it
     * @param string|int[] $size Requested size
     * @param mixed $icon Whether to use icon
     * @return array|false
     */
    public function filterAttachmentImageSrc($image, $attachmentId = 0, $size = 'thumbnail', $icon = false)
    {
        $attachmentId = (int) $attachmentId;
        $icon = (bool) $icon;

        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return $image;
        }

        // Get thumbnail
        $thumbnailId = $this->generator->getThumbnailId($attachmentId);
        if (!$thumbnailId) {
            return $image;
        }

        // Return thumbnail image source, or core's answer if the thumbnail
        // has none (its file is gone).
        $thumbnail = wp_get_attachment_image_src($thumbnailId, $size, $icon);

        return false !== $thumbnail ? $thumbnail : $image;
    }

    /**
     * Filter attachment image HTML for PDFs
     *
     * Untyped and cast, as filterAttachmentImageSrc() is (review of 1.4.32).
     *
     * @param mixed $html Image HTML
     * @param mixed $attachmentId Attachment ID, as the caller gave it
     * @param string|int[] $size Image size
     * @param mixed $icon Whether icon
     * @param string|array $attr Attributes (core passes '' by default; plugins may pass a string or an array)
     * @return mixed
     */
    public function filterAttachmentImage($html, $attachmentId = 0, $size = 'thumbnail', $icon = false, $attr = '')
    {
        if (!is_string($html)) {
            return $html;
        }

        $attachmentId = (int) $attachmentId;
        $icon = (bool) $icon;

        // A fallback, not the usual route. filterAttachmentImageSrc() above
        // already gives core the thumbnail, so core's HTML is not empty for a
        // PDF that has one. It is empty only when something later on
        // wp_get_attachment_image_src took that answer away, and then this
        // still shows the thumbnail (review after 1.4.31: read as dead code,
        // which it is only while nothing else interferes).
        // Only process if HTML is empty
        if (!empty($html)) {
            return $html;
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return $html;
        }

        // Normalize $attr — core defaults to '' (string) and the filter signature
        // is documented as string|array, so we must accept both.
        if (is_string($attr)) {
            $attr = $attr === '' ? [] : wp_parse_args($attr);
        } elseif (!is_array($attr)) {
            $attr = [];
        }

        // Get thumbnail image
        // A size of null or a number from another plugin's call was a
        // TypeError in getThumbnailImage(), which takes a name (review after
        // 1.4.32).
        if (is_array($size)) {
            $size = 'full';
        } elseif (!is_scalar($size) || '' === (string) $size) {
            $size = 'thumbnail';
        }

        return $this->generator->getThumbnailImage($attachmentId, (string) $size, $attr);
    }

    /**
     * Filter attachment data for JavaScript (Media Library & Block Editor)
     *
     * Untyped, as the other filters here are (review of 1.4.34).
     *
     * @param mixed $response Attachment response data
     * @param mixed $attachment Attachment post object
     * @param array|bool $meta Attachment meta data
     * @return mixed Modified response
     */
    public function filterAttachmentForJs($response, $attachment = null, $meta = false)
    {
        if (!is_array($response) || !$attachment instanceof \WP_Post) {
            return $response;
        }

        // Check if this is a generated thumbnail - show source PDF URL instead
        $isThumbnail = get_post_meta($attachment->ID, '_rapls_pic_is_thumbnail', true);
        $sourcePdfId = get_post_meta($attachment->ID, '_rapls_pic_source_pdf', true);

        // The two marks only. A guess from post_parent -- "for older
        // thumbnails without meta" -- took any image another plugin attached
        // to a PDF for this plugin's, and gave it the PDF's URL. There are no
        // such thumbnails: the first version published on WordPress.org
        // (1.0.6) already set both marks (R66-03).

        if (!empty($isThumbnail) && !empty($sourcePdfId)) {
            $pdfUrl = wp_get_attachment_url((int) $sourcePdfId);
            if ($pdfUrl) {
                // Replace URL with source PDF URL for "File URL" field and "Copy URL" button
                $response['url'] = $pdfUrl;
                // Also update link if it exists
                if (isset($response['link'])) {
                    $response['link'] = get_attachment_link((int) $sourcePdfId);
                }
                // Add marker for JavaScript
                $response['picSourcePdfId'] = (int) $sourcePdfId;
                $response['picSourcePdfUrl'] = $pdfUrl;
                $response['picIsThumbnail'] = true;
                // With url now the PDF's, a size the image has no file for
                // must not fall back to url (see withEverySize()).
                if (is_array($response['sizes'] ?? null) && is_array($response['sizes']['full'] ?? null)) {
                    $response['sizes'] = self::withEverySize($response['sizes'], $response['sizes']['full']);
                }
            }
            return $response;
        }

        // Only process PDFs
        if ('application/pdf' !== ($response['mime'] ?? '')) {
            return $response;
        }

        // Get thumbnail ID
        $thumbnailId = $this->generator->getThumbnailId($attachment->ID);
        if (!$thumbnailId) {
            return $response;
        }

        // Get thumbnail metadata
        $thumbnailMeta = wp_get_attachment_metadata($thumbnailId);
        if (!$thumbnailMeta) {
            return $response;
        }

        // Get thumbnail URL
        $thumbnailUrl = wp_get_attachment_url($thumbnailId);
        if (!$thumbnailUrl) {
            return $response;
        }

        // The sizes sit beside the full image, so their addresses are its
        // address with the file name swapped -- as core's own
        // wp_prepare_attachment_for_js() builds them. Built from the uploads
        // folder's address instead, they went past a CDN or offload plugin
        // that had rewritten the full image's, to a local copy that need not
        // exist (review after 1.4.31).
        $sizeBase = str_replace(wp_basename($thumbnailUrl), '', $thumbnailUrl);

        // Keep PDF URL for "File URL" field - DO NOT replace with thumbnail URL
        // $response['url'] stays as the PDF URL
        // Only set image dimensions from thumbnail for display purposes
        $response['width'] = $thumbnailMeta['width'] ?? 0;
        $response['height'] = $thumbnailMeta['height'] ?? 0;

        // Build sizes array from thumbnail sizes
        $sizes = [];

        // Add full size
        $sizes['full'] = [
            'url' => $thumbnailUrl,
            'width' => $thumbnailMeta['width'] ?? 0,
            'height' => $thumbnailMeta['height'] ?? 0,
            'orientation' => ($thumbnailMeta['width'] ?? 0) >= ($thumbnailMeta['height'] ?? 0) ? 'landscape' : 'portrait',
        ];

        // Add other sizes if available
        if (!empty($thumbnailMeta['sizes']) && is_array($thumbnailMeta['sizes'])) {
            foreach ($thumbnailMeta['sizes'] as $sizeName => $sizeData) {
                // A size another plugin wrote without its file or measures
                // is left out, not read as a warning (review after 1.4.37).
                if (!self::isSize($sizeData)) {
                    continue;
                }
                $sizes[$sizeName] = [
                    'url' => $sizeBase . $sizeData['file'],
                    'width' => $sizeData['width'],
                    'height' => $sizeData['height'],
                    'orientation' => $sizeData['width'] >= $sizeData['height'] ? 'landscape' : 'portrait',
                ];
            }
        }

        $response['sizes'] = self::withEverySize($sizes, $sizes['full']);

        // Mark as having a PDF thumbnail
        $response['picThumbnailId'] = $thumbnailId;

        return $response;
    }

    /**
     * Every registered size, the full image standing in for any not made
     *
     * The block editor looks for the size it wants -- a gallery asks for
     * "large" -- and, when there is none, falls back to the attachment's own
     * url or source_url, which for a PDF is the PDF. WordPress makes no
     * "large" for an image smaller than the large box, and the thumbnail is
     * 1024 pixels at most by default, so a PDF put in a gallery became
     * <img src="....pdf"> (review of 1.4.32). For an image, the fallback is
     * the image itself; this gives the PDF the same.
     *
     * @param array<string, array<string, mixed>> $sizes The sizes there are, with 'full'.
     * @param array<string, mixed>                $full  The full size's entry.
     * @return array<string, array<string, mixed>>
     */
    public static function withEverySize(array $sizes, array $full): array
    {
        $names = function_exists('get_intermediate_image_sizes') ? (array) get_intermediate_image_sizes() : [];

        foreach (array_merge(['thumbnail', 'medium', 'large'], $names) as $name) {
            if (is_string($name) && '' !== $name && !isset($sizes[$name])) {
                $sizes[$name] = $full;
            }
        }

        return $sizes;
    }

    /**
     * Filter REST API response for attachments (Block Editor)
     *
     * Untyped: an earlier listener can hand on a WP_Error or anything else,
     * and a typed parameter made that a fatal error for the whole REST
     * request. What is not a response for a post is handed on as it came
     * (review of 1.4.35).
     *
     * @param mixed $response REST response object
     * @param mixed $post Attachment post object
     * @param mixed $request REST request object
     * @return mixed Modified response
     */
    public function filterRestAttachment($response, $post = null, $request = null)
    {
        if (!$response instanceof \WP_REST_Response || !$post instanceof \WP_Post) {
            return $response;
        }

        // Check if this is a generated thumbnail - show source PDF URL instead
        $isThumbnail = get_post_meta($post->ID, '_rapls_pic_is_thumbnail', true);
        $sourcePdfId = get_post_meta($post->ID, '_rapls_pic_source_pdf', true);

        // The two marks only. A guess from post_parent -- "for older
        // thumbnails without meta" -- took any image another plugin attached
        // to a PDF for this plugin's, and gave it the PDF's URL. There are no
        // such thumbnails: the first version published on WordPress.org
        // (1.0.6) already set both marks (R66-03).

        if (!empty($isThumbnail) && !empty($sourcePdfId)) {
            $pdfUrl = wp_get_attachment_url((int) $sourcePdfId);
            if ($pdfUrl) {
                $data = $response->get_data();
                // source_url and link stay core's: the REST schema defines
                // source_url as this attachment's own file, and the block
                // editor reads it as the image to show. Replaced with the
                // PDF's, the response said image/jpeg and handed over a PDF
                // (R68-02). The PDF is in the fields of this plugin's own.
                $data['rapls_pic_source_pdf_id'] = (int) $sourcePdfId;
                $data['rapls_pic_source_pdf_url'] = $pdfUrl;
                $response->set_data($data);
            }
            return $response;
        }

        // Only process PDFs
        $mimeType = get_post_mime_type($post->ID);
        if ($mimeType !== 'application/pdf') {
            return $response;
        }

        // Get thumbnail ID
        $thumbnailId = $this->generator->getThumbnailId($post->ID);
        if (!$thumbnailId) {
            return $response;
        }

        // Get thumbnail metadata
        $thumbnailMeta = wp_get_attachment_metadata($thumbnailId);
        if (!$thumbnailMeta) {
            return $response;
        }

        // Get thumbnail URL
        $thumbnailUrl = wp_get_attachment_url($thumbnailId);
        if (!$thumbnailUrl) {
            return $response;
        }

        $data = $response->get_data();

        // The sizes sit beside the full image, so their addresses are its
        // address with the file name swapped -- as core's own
        // wp_prepare_attachment_for_js() builds them. Built from the uploads
        // folder's address instead, they went past a CDN or offload plugin
        // that had rewritten the full image's, to a local copy that need not
        // exist (review after 1.4.31).
        $sizeBase = str_replace(wp_basename($thumbnailUrl), '', $thumbnailUrl);
        $thumbnailFile = (string) get_post_meta($thumbnailId, '_wp_attached_file', true);

        // Keep PDF URL for source_url - DO NOT replace with thumbnail URL
        // $data['source_url'] stays as the PDF URL

        // Build media_details from thumbnail
        $mediaDetails = [
            'width' => $thumbnailMeta['width'] ?? 0,
            'height' => $thumbnailMeta['height'] ?? 0,
            'file' => $thumbnailFile,
            'sizes' => [],
        ];

        // Add full size
        $mediaDetails['sizes']['full'] = [
            'file' => basename($thumbnailFile),
            'width' => $thumbnailMeta['width'] ?? 0,
            'height' => $thumbnailMeta['height'] ?? 0,
            'mime_type' => get_post_mime_type($thumbnailId),
            'source_url' => $thumbnailUrl,
        ];

        // Add other sizes
        if (!empty($thumbnailMeta['sizes']) && is_array($thumbnailMeta['sizes'])) {
            foreach ($thumbnailMeta['sizes'] as $sizeName => $sizeData) {
                if (!self::isSize($sizeData)) {
                    continue;
                }
                $mediaDetails['sizes'][$sizeName] = [
                    'file' => $sizeData['file'],
                    'width' => $sizeData['width'],
                    'height' => $sizeData['height'],
                    'mime_type' => $sizeData['mime-type'] ?? get_post_mime_type($thumbnailId),
                    'source_url' => $sizeBase . $sizeData['file'],
                ];
            }
        }

        $mediaDetails['sizes'] = self::withEverySize($mediaDetails['sizes'], $mediaDetails['sizes']['full']);
        $data['media_details'] = $mediaDetails;

        // Add custom field to indicate PDF thumbnail
        $data['rapls_pic_thumbnail_id'] = $thumbnailId;

        $response->set_data($data);

        return $response;
    }

    /**
     * Filter media library to hide generated thumbnails
     *
     * @param \WP_Query $query Query object
     */
    public function filterMediaLibrary($query = null): void
    {
        if (!$query instanceof \WP_Query || !is_admin()) {
            return;
        }

        // Only the Media Library's own list: the screen's main query. Any
        // attachment query in the admin used to qualify -- another plugin's
        // picker, a maintenance job -- and lost this plugin's images from its
        // answer, with no way to ask for them (R67-03). The media modal asks
        // through admin-ajax and is handled in allowPdfsInImageSelection().
        global $pagenow;

        if ('upload.php' !== $pagenow || !$query->is_main_query()) {
            return;
        }

        // Only filter media library queries
        if ($query->get('post_type') !== 'attachment') {
            return;
        }

        // The plugin's own queries are not the media library. Bulk Generate
        // asks for every PDF and then decides what to do with them; hiding
        // generated images from that answer would be the plugin arguing with
        // itself, and the argument is invisible from the screen where it shows
        // up as "PDFs found: 0".
        if ($query->get('rapls_pic_internal')) {
            return;
        }

        // Check if we should hide thumbnails (setting + filter)
        $hideThumbnails = $this->settings->shouldHideGeneratedImages();
        $hideThumbnails = apply_filters('rapls_pdf_image_creator_hide_thumbnails_in_library', $hideThumbnails);
        if (!$hideThumbnails) {
            return;
        }

        // Exclude thumbnails created by this plugin
        $query->set('meta_query', self::withoutGeneratedImages($query->get('meta_query')));
    }

    /**
     * Add custom columns to media library
     *
     * Untyped, as the other filters here are (review of 1.4.34).
     *
     * @param mixed $columns Existing columns
     * @return mixed Modified columns
     */
    public function addMediaColumns($columns)
    {
        if (!is_array($columns)) {
            return $columns;
        }
        $columns['rapls_pic_thumbnail'] = __('PDF Thumbnail', 'rapls-pdf-image-creator');
        return $columns;
    }

    /**
     * Render custom column content
     *
     * @param string $columnName Column name
     * @param int $postId Post ID
     */
    public function renderMediaColumn($columnName = '', $postId = 0): void
    {
        $postId = (int) $postId;
        if ('rapls_pic_thumbnail' !== $columnName || $postId < 1) {
            return;
        }

        // Only for PDFs
        $mimeType = get_post_mime_type($postId);
        if ($mimeType !== 'application/pdf') {
            echo '—';
            return;
        }

        $this->primeListRows();

        // The stylesheet's class names (they were "pic-status", so the marks
        // were never styled), and the words for a screen reader, which reads
        // a title only sometimes (review after 1.4.31).
        if ($this->generator->hasThumbnail($postId)) {
            echo '<span class="rapls-pic-status rapls-pic-status-yes" title="' . esc_attr__('Thumbnail generated', 'rapls-pdf-image-creator') . '" aria-hidden="true">✓</span>'
                . '<span class="screen-reader-text">' . esc_html__('Thumbnail generated', 'rapls-pdf-image-creator') . '</span>';
        } else {
            echo '<span class="rapls-pic-status rapls-pic-status-no" title="' . esc_attr__('No thumbnail', 'rapls-pdf-image-creator') . '" aria-hidden="true">✗</span>'
                . '<span class="screen-reader-text">' . esc_html__('No thumbnail', 'rapls-pdf-image-creator') . '</span>';
        }
    }

    /**
     * Add row actions to media library
     *
     * Untyped: another plugin's earlier filter can hand on null for the
     * actions, and what is not a post gets none (review of 1.4.35).
     *
     * @param mixed $actions Existing actions
     * @param mixed $post Post object
     * @return mixed Modified actions
     */
    public function addRowActions($actions, $post = null)
    {
        if (!is_array($actions) || !$post instanceof \WP_Post) {
            return $actions;
        }

        // Only for PDFs
        if (get_post_mime_type($post->ID) !== 'application/pdf') {
            return $actions;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $this->primeListRows();

        // Add regenerate action
        $url = wp_nonce_url(
            admin_url('upload.php?action=rapls_pic_regenerate&attachment_id=' . $post->ID),
            'rapls_pic_regenerate_' . $post->ID
        );

        if ($this->generator->hasThumbnail($post->ID)) {
            $actions['rapls_pic_regenerate'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Regenerate Thumbnail', 'rapls-pdf-image-creator')
            );
        } else {
            $actions['rapls_pic_generate'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($url),
                esc_html__('Generate Thumbnail', 'rapls-pdf-image-creator')
            );
        }

        return $actions;
    }

    /**
     * Read the images the list's PDFs name, in one query, the first time
     *
     * hasThumbnail() asks whether each PDF's image still exists, and in the
     * Media Library's list view that was a query per PDF row, twice over
     * with the row actions (review of 1.4.34). The list is the screen's main
     * query; its meta is read already, so this adds one query, not one a row.
     */
    private function primeListRows(): void
    {
        if ($this->listPrimed) {
            return;
        }
        $this->listPrimed = true;

        global $wp_query;
        if (!is_object($wp_query) || !isset($wp_query->posts) || !is_array($wp_query->posts) || !function_exists('_prime_post_caches')) {
            return;
        }

        $pdfs = [];
        foreach ($wp_query->posts as $post) {
            if (is_object($post) && isset($post->ID, $post->post_mime_type) && 'application/pdf' === $post->post_mime_type) {
                $pdfs[] = (int) $post->ID;
            }
        }
        if ([] === $pdfs) {
            return;
        }

        if (function_exists('update_postmeta_cache')) {
            update_postmeta_cache($pdfs);
        }

        $named = [];
        foreach ($pdfs as $pdf) {
            foreach ([Generator::THUMBNAIL_META_KEY, '_thumbnail_id'] as $key) {
                $image = (int) get_post_meta($pdf, $key, true);
                if ($image > 0) {
                    $named[$image] = $image;
                }
            }
        }

        if ([] !== $named) {
            _prime_post_caches(array_values($named), false, false);
        }
    }

    /**
     * Handle regenerate action from media library
     */
    public function handleRegenerateAction(): void
    {
        if (!isset($_GET['action']) || $_GET['action'] !== 'rapls_pic_regenerate') {
            return;
        }

        $attachmentId = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        if (!$attachmentId) {
            return;
        }

        // Verify nonce
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rapls_pic_regenerate_' . $attachmentId)) {
            wp_die(esc_html__('Security check failed.', 'rapls-pdf-image-creator'));
        }

        // Check permissions
        if (!current_user_can('edit_post', $attachmentId)) {
            wp_die(esc_html__('You do not have permission to do this.', 'rapls-pdf-image-creator'));
        }

        // Generate thumbnail
        $result = $this->generator->generate($attachmentId, true);

        // Back to the list as it was -- its page, search and filter -- not to
        // its first page (review of 1.4.32). Only to the Media Library.
        $redirectUrl = (string) wp_get_referer();
        if ('' === $redirectUrl || false === strpos($redirectUrl, 'upload.php')) {
            $redirectUrl = admin_url('upload.php');
        }
        $redirectUrl = remove_query_arg(['rapls_pic_generated', 'rapls_pic_error'], $redirectUrl);
        if ($result) {
            // "kept": the old thumbnail stays, a post shows it, and the
            // notice says so (review after 1.4.37).
            $kept = $this->generator->outcomeFor($attachmentId)['kept_previous'] ?? null;
            $generated = '1';
            if (null !== $kept) {
                // Why it was kept, as the notice says it (Admin::KEPT_IN_ADDRESS):
                // "kept" alone for a post's content, as it first was.
                $reason = $this->generator->keptReasonFor($attachmentId) ?? Generator::KEPT_SHOWN;
                $generated = Generator::KEPT_SHOWN === $reason ? 'kept' : 'kept-' . $reason;
            }
            $redirectUrl = add_query_arg('rapls_pic_generated', $generated, $redirectUrl);
        } else {
            // The code, not the message: the notice says it in words of its
            // own, so nothing from ImageMagick -- a command line, a server
            // path -- reaches whoever can edit this PDF, and it is this
            // attempt's reason, not the site's last (review after 1.4.32).
            $outcome = $this->generator->outcomeFor($attachmentId);
            $code = sanitize_key((string) ($outcome['code'] ?? ''));
            $redirectUrl = add_query_arg('rapls_pic_error', '' !== $code ? $code : '1', $redirectUrl);
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * AJAX handler for regenerating a thumbnail, as 1.4.20 had it
     *
     * Kept for code that hooked it itself: a public method is not removed in
     * a release. Nothing here hooks it any more; the row action regenerates
     * through handleRegenerateAction(). Every check it made is still made --
     * the nonce, upload_files, edit_post on this PDF -- before anything is
     * drawn.
     *
     * @deprecated 1.4.45 Use the "Regenerate Thumbnail" row action.
     */
    public function ajaxRegenerateThumbnail(): void
    {
        check_ajax_referer('rapls_pic_admin', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
        }

        $attachmentId = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachmentId) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'rapls-pdf-image-creator')]);
        }

        if (!current_user_can('edit_post', $attachmentId)) {
            wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')], 403);
        }

        if ('application/pdf' !== get_post_mime_type($attachmentId)) {
            wp_send_json_error(['message' => __('Not a PDF file.', 'rapls-pdf-image-creator')]);
        }

        $result = $this->generator->generate($attachmentId, !empty($_POST['force']));

        if ($result) {
            wp_send_json_success([
                'message' => __('Thumbnail generated successfully.', 'rapls-pdf-image-creator'),
                'thumbnail_id' => $result,
                'thumbnail_url' => $this->generator->getThumbnailUrl($attachmentId, 'thumbnail'),
            ]);
        }
        wp_send_json_error(['message' => __('Failed to generate thumbnail.', 'rapls-pdf-image-creator')]);
    }

    /**
     * Whether a size in an image's metadata has the file and measures read from it
     *
     * @param mixed $size
     */
    private static function isSize($size): bool
    {
        return is_array($size) && isset($size['file'], $size['width'], $size['height'])
            && is_string($size['file']) && '' !== $size['file'] && is_numeric($size['width']) && is_numeric($size['height']);
    }

    /**
     * The type WordPress's own image pickers add to their library query
     *
     * Added by admin/js/block-editor.js (the image block's picker) and
     * admin/js/core-media-states.js (core's featured image and gallery
     * states). Not a real MIME
     * type: it matches no attachment, and is taken out here before the query
     * runs. It says the picker is one this plugin opened to PDFs, which a
     * plain "image" query does not.
     */
    public const PICKER_MARK = 'rapls-pic/with-thumbnail';

    /**
     * Allow PDFs with thumbnails in image selection queries and hide generated thumbnails
     *
     * Untyped, as the other filters here are (review of 1.4.34).
     *
     * @param mixed $query Query arguments
     * @return mixed Modified query arguments
     */
    public function allowPdfsInImageSelection($query)
    {
        if (!is_array($query)) {
            return $query;
        }

        // Hide generated thumbnails from the media window's queries: every
        // one, wherever the window was opened -- another plugin's included,
        // since it is the same window. Other attachment queries are left
        // alone (filterMediaLibrary()).
        $hideThumbnails = $this->settings->shouldHideGeneratedImages();
        $hideThumbnails = apply_filters('rapls_pdf_image_creator_hide_thumbnails_in_library', $hideThumbnails);

        if ($hideThumbnails) {
            $query['meta_query'] = self::withoutGeneratedImages($query['meta_query'] ?? []);
        }

        // Check if this is an image-only query
        if (!isset($query['post_mime_type'])) {
            return $query;
        }

        $mimeType = $query['post_mime_type'];

        // Only a picker of WordPress's own that this plugin opened up to PDFs
        // -- the image block, the gallery, the featured image -- carries the
        // mark (admin/js/block-editor.js). Any image query used to get PDFs:
        // another plugin's image picker too, which can take only images, and
        // was handed a PDF (Codex review of 1.4.29, 1).
        //
        // A site whose builder or field plugin took PDFs that way, as 1.4.20
        // let every image picker do, can have it back: the filter below
        // returning true puts PDFs with thumbnails into every query for
        // images (review after 1.4.37).
        $marked = is_array($mimeType) && in_array(self::PICKER_MARK, $mimeType, true);
        if (!$marked) {
            $asksImages = 'image' === $mimeType || (is_array($mimeType) && in_array('image', $mimeType, true));
            /**
             * Whether every image picker is given PDFs with thumbnails, as in 1.4.20
             *
             * Off by default: only WordPress's own image block, gallery and
             * featured image pickers are. On, any media query for "image"
             * lists PDFs that have a thumbnail too, as every one did up to
             * 1.4.20, and in the block editor every image picker is told it
             * may take them. A picker whose media window filters by type
             * itself -- the classic "Create Gallery", most page builders'
             * own screens, where this plugin's block editor script does not
             * load -- still leaves PDFs out, as it did with 1.4.20. A picker
             * that can take only images may be handed a PDF.
             *
             * @since 1.4.45
             *
             * @param bool  $every Whether to.
             * @param array $query The media query's arguments. Asked once
             *                     more with [] when the block editor
             *                     loads, for its pickers' side
             *                     (enqueueBlockEditorAssets()): a listener
             *                     that decides by the query answers then
             *                     for no query in particular.
             */
            if (!$asksImages || true !== apply_filters('rapls_pdf_image_creator_pdfs_in_every_image_picker', false, $query)) {
                return $query;
            }
            $mimeType = (array) $mimeType;
        }

        $types = array_values(array_diff($mimeType, [self::PICKER_MARK]));

        // PDFs that have thumbnails -- those only, which
        // limitPdfsToThumbnailed() sees to. Every PDF went in, and one with no
        // thumbnail reached an image picker as a plain PDF, with no image to
        // show (R66-02).
        if (!in_array('application/pdf', $types, true)) {
            $types[] = 'application/pdf';
        }

        $query['post_mime_type'] = $types;
        $query['rapls_pic_image_pick'] = true;

        return $query;
    }

    /**
     * In an image picker, a PDF only when it has a thumbnail to show
     *
     * The same two keys getThumbnailId() reads, and the image they name must
     * still be there: a PDF is let through only when one of the keys points
     * at an attachment that exists. A key left behind by an image deleted
     * from the library does not let it in (R67-01) -- the picker would have
     * had no image to show for it.
     *
     * @param string    $where The query's WHERE clause
     * @param \WP_Query $query The query
     */
    public function limitPdfsToThumbnailed($where, $query = null)
    {
        // Untyped, with a default: a plugin running posts_where by hand, with
        // one argument or a non-string, was a fatal error here (review of
        // 1.4.32).
        if (!is_string($where) || !$query instanceof \WP_Query || !$query->get('rapls_pic_image_pick')) {
            return $where;
        }

        global $wpdb;

        // And the image the key names is there: a key left behind by an
        // image deleted from the library let the PDF in, and the response
        // then had no image for it (R67-01) -- getThumbnailId() asks the same.
        return $where . " AND ({$wpdb->posts}.post_mime_type <> 'application/pdf' OR EXISTS ("
            . "SELECT 1 FROM {$wpdb->postmeta} rapls_pic_thumb"
            . " INNER JOIN {$wpdb->posts} rapls_pic_image ON rapls_pic_image.ID = CAST(rapls_pic_thumb.meta_value AS UNSIGNED)"
            . " WHERE rapls_pic_thumb.post_id = {$wpdb->posts}.ID"
            . " AND rapls_pic_thumb.meta_key IN ('_rapls_pic_thumbnail_id', '_thumbnail_id') AND rapls_pic_thumb.meta_value NOT IN ('', '0')"
            . " AND rapls_pic_image.post_type = 'attachment'))";
    }

    /**
     * Add "not a generated image" to a meta query, as a clause of its own
     *
     * Appended to the same level, it joined whatever relation the query
     * already had: under 'OR' it widened another plugin's conditions instead
     * of narrowing them (R66-01). The existing query is kept whole and the
     * two are joined with AND.
     *
     * @param mixed $metaQuery The query's meta_query, as given
     * @return array<int|string, mixed>
     */
    public static function withoutGeneratedImages($metaQuery): array
    {
        $hide = [
            'relation' => 'OR',
            [
                'key' => '_rapls_pic_is_thumbnail',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => '_rapls_pic_is_thumbnail',
                'value' => '1',
                'compare' => '!=',
            ],
        ];

        if (!is_array($metaQuery) || [] === $metaQuery) {
            return [$hide];
        }

        return ['relation' => 'AND', $metaQuery, $hide];
    }

    /**
     * Enqueue block editor assets
     */
    public function enqueueBlockEditorAssets(): void
    {
        $this->registerCoreMediaStates();

        wp_enqueue_script(
            'pic-block-editor',
            RAPLS_PIC_PLUGIN_URL . 'admin/js/block-editor.js',
            // What the script itself uses, not what wp-edit-post happens to
            // bring along (R68-03) -- and not wp-edit-post itself, wp-blocks
            // or wp-dom-ready, which it never used: listed, they loaded the
            // post editor's code into the site, widget and customizer editors
            // too (review after 1.4.31). wp.media is optional and checked for.
            ['wp-hooks', 'wp-compose', 'wp-element', 'wp-data', 'wp-notices', 'wp-api-fetch', 'pic-core-media-states'],
            RAPLS_PIC_VERSION,
            true
        );

        // Whether a PDF uploaded in the editor's media window gets a
        // thumbnail, which the pickers say and act on.
        wp_localize_script('pic-block-editor', 'raplsPicBlockEditor', [
            'autoGenerate' => $this->settings->isAutoGenerateEnabled(),
            // Said when a PDF without a thumbnail is chosen for an image
            // block -- one uploaded in the media window with Auto Generate
            // off, or whose generation failed (Codex review of 1.4.26, 3).
            'noThumbnail' => $this->settings->isAutoGenerateEnabled()
                ? __('This PDF has no thumbnail, so it cannot be shown as an image. In Media > Library, in list view, use "Generate Thumbnail" under the PDF, then choose it again.', 'rapls-pdf-image-creator')
                : __('This PDF has no thumbnail, so it cannot be shown as an image: automatic thumbnail generation is off. In Media > Library, in list view, use "Generate Thumbnail" under the PDF, then choose it again.', 'rapls-pdf-image-creator'),
            // With Auto Generate off: said on the upload tab before anything
            // is uploaded, and the PDF just uploaded can be deleted again
            // (Codex review of 1.4.27, 2).
            'uploadMessage' => __('Automatic thumbnail generation is off, so a PDF uploaded here gets no thumbnail and cannot be used in an image block. PDFs that already have a thumbnail can be chosen in the Media Library tab.', 'rapls-pdf-image-creator'),
            'deleteUploaded' => __('Delete the uploaded PDF', 'rapls-pdf-image-creator'),
            'deleted' => __('The PDF was deleted from the Media Library.', 'rapls-pdf-image-creator'),
            'deleteFailed' => __('The PDF could not be deleted. Delete it in the Media Library.', 'rapls-pdf-image-creator'),
            // rapls_pdf_image_creator_pdfs_in_every_image_picker, for the
            // pickers' side: their allowed types and the media window's
            // library, as 1.4.20 set them (review of 1.4.37's fixes).
            'everyPicker' => true === apply_filters('rapls_pdf_image_creator_pdfs_in_every_image_picker', false, []),
        ]);
    }

    /**
     * The classic editor's "Set featured image" and "Add Media" offer PDFs with a thumbnail
     *
     * As the block editor's pickers do. Only on a post screen the block
     * editor is not running on: there, enqueueBlockEditorAssets() loads the
     * same script.
     * Any other screen that loads the media library is left as it is
     * (Codex review of 1.4.29, 1).
     */
    public function enqueueClassicFeaturedImage(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        // Not an attachment's own edit screen (an audio or video file can
        // have a featured image too): the picker there is not a post's.
        if (!is_object($screen) || 'post' !== ($screen->base ?? '') || 'attachment' === ($screen->post_type ?? '')
            || (method_exists($screen, 'is_block_editor') && $screen->is_block_editor())
        ) {
            return;
        }

        $this->registerCoreMediaStates();
        wp_enqueue_script('pic-core-media-states');
    }

    /**
     * The script that opens WordPress's own featured image and gallery pickers to PDFs
     *
     * admin/js/core-media-states.js marks the libraries of core's
     * featured-image, gallery and gallery-library states, and nothing else.
     */
    private function registerCoreMediaStates(): void
    {
        wp_register_script(
            'pic-core-media-states',
            RAPLS_PIC_PLUGIN_URL . 'admin/js/core-media-states.js',
            ['media-views'],
            RAPLS_PIC_VERSION,
            true
        );
    }

    /**
     * Filter attachment image attributes for PDFs
     *
     * Untyped, as the other filters here are: a null post from another
     * plugin's call was a fatal error (review of 1.4.33).
     *
     * @param mixed $attr Image attributes
     * @param mixed $attachment Attachment post object
     * @param string|int[] $size Image size
     * @return mixed Modified attributes
     */
    public function filterAttachmentImageAttributes($attr, $attachment = null, $size = 'thumbnail')
    {
        if (!is_array($attr) || !is_object($attachment) || empty($attachment->ID)) {
            return $attr;
        }

        // Check if display thumbnail icon is enabled
        if (!$this->settings->shouldDisplayThumbnailIcon()) {
            return $attr;
        }

        // Only process PDFs
        if (get_post_mime_type((int) $attachment->ID) !== 'application/pdf') {
            return $attr;
        }

        // Get thumbnail
        $thumbnailId = $this->generator->getThumbnailId((int) $attachment->ID);
        if (!$thumbnailId) {
            return $attr;
        }

        // Get thumbnail URL, at the size core was asked for. A size given as
        // width and height used to become the 150px square here, under the
        // width and height core had already written for the size asked --
        // a stretched picture, on the front end too (review of 1.4.33).
        // Core picks the closest size for an array, as it did for the
        // width and height.
        if (!is_array($size) && (!is_scalar($size) || '' === (string) $size)) {
            $size = 'thumbnail';
        }
        $thumbnailSrc = wp_get_attachment_image_src($thumbnailId, $size);
        if ($thumbnailSrc) {
            $attr['src'] = $thumbnailSrc[0];
            if (isset($attr['srcset'])) {
                unset($attr['srcset']);
            }
        }

        return $attr;
    }

    /**
     * Filter mime type icon for PDFs
     *
     * Untyped and cast: core passes false for an icon it did not find,
     * and the post ID as it was given (review of 1.4.32).
     *
     * @param mixed $icon Icon URL, or false
     * @param mixed $mime MIME type
     * @param mixed $postId Post ID
     * @return mixed Modified icon URL
     */
    public function filterMimeTypeIcon($icon, $mime = '', $postId = 0)
    {
        $postId = (int) $postId;

        // Check if display thumbnail icon is enabled
        if (!$this->settings->shouldDisplayThumbnailIcon()) {
            return $icon;
        }

        // Only process PDFs
        if ($mime !== 'application/pdf') {
            return $icon;
        }

        // Get thumbnail
        $thumbnailId = $this->generator->getThumbnailId($postId);
        if (!$thumbnailId) {
            return $icon;
        }

        // Get thumbnail URL
        $thumbnailUrl = wp_get_attachment_image_url($thumbnailId, 'thumbnail');
        if ($thumbnailUrl) {
            return $thumbnailUrl;
        }

        return $icon;
    }
}
