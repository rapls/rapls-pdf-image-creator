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
        add_action('wp_ajax_rapls_pic_regenerate_thumbnail', [$this, 'ajaxRegenerateThumbnail']);

        // Allow PDFs in image block media selection
        add_filter('ajax_query_attachments_args', [$this, 'allowPdfsInImageSelection']);

        // Add PDF support info to block editor
        add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);

        // Replace PDF icon with thumbnail in media library
        add_filter('wp_get_attachment_image_attributes', [$this, 'filterAttachmentImageAttributes'], 10, 3);
        add_filter('wp_mime_type_icon', [$this, 'filterMimeTypeIcon'], 10, 3);
    }

    /**
     * Handle new attachment upload
     *
     * @param int $attachmentId Attachment ID
     */
    public function onAttachmentAdded(int $attachmentId): void
    {
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
     * Handle attachment deletion
     *
     * @param int $attachmentId Attachment ID
     */
    public function onAttachmentDeleted(int $attachmentId): void
    {
        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return;
        }

        // Only delete thumbnail if hide_generated_images is enabled
        // When unchecked, thumbnails are visible and should be kept as independent images
        if ($this->settings->shouldHideGeneratedImages()) {
            $this->generator->deleteThumbnail($attachmentId);
        } else {
            // Just remove the meta link, but keep the image file
            $thumbnailId = $this->generator->getThumbnailId($attachmentId);
            if ($thumbnailId) {
                // Remove the "is thumbnail" marker so it becomes a regular image
                delete_post_meta($thumbnailId, '_rapls_pic_is_thumbnail');
                delete_post_meta($thumbnailId, '_rapls_pic_source_pdf');
            }
            delete_post_meta($attachmentId, Generator::THUMBNAIL_META_KEY);
            delete_post_meta($attachmentId, '_thumbnail_id');
        }
    }

    /**
     * Filter attachment image source for PDFs
     *
     * @param array|false $image Image data or false
     * @param int $attachmentId Attachment ID
     * @param string|int[] $size Requested size
     * @param bool $icon Whether to use icon
     * @return array|false
     */
    public function filterAttachmentImageSrc($image, int $attachmentId, $size, bool $icon)
    {
        // Only process if no image found
        if ($image !== false) {
            return $image;
        }

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

        // Return thumbnail image source
        return wp_get_attachment_image_src($thumbnailId, $size, $icon);
    }

    /**
     * Filter attachment image HTML for PDFs
     *
     * @param string $html Image HTML
     * @param int $attachmentId Attachment ID
     * @param string|int[] $size Image size
     * @param bool $icon Whether icon
     * @param array $attr Attributes
     * @return string
     */
    public function filterAttachmentImage(string $html, int $attachmentId, $size, bool $icon, array $attr): string
    {
        // Only process if HTML is empty
        if (!empty($html)) {
            return $html;
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return $html;
        }

        // Get thumbnail image
        return $this->generator->getThumbnailImage($attachmentId, is_array($size) ? 'full' : $size, $attr);
    }

    /**
     * Filter attachment data for JavaScript (Media Library & Block Editor)
     *
     * @param array $response Attachment response data
     * @param \WP_Post $attachment Attachment post object
     * @param array|bool $meta Attachment meta data
     * @return array Modified response
     */
    public function filterAttachmentForJs(array $response, \WP_Post $attachment, $meta): array
    {
        // Check if this is a generated thumbnail - show source PDF URL instead
        $isThumbnail = get_post_meta($attachment->ID, '_rapls_pic_is_thumbnail', true);
        $sourcePdfId = get_post_meta($attachment->ID, '_rapls_pic_source_pdf', true);

        // Also check by post_parent for older thumbnails without meta
        if (empty($sourcePdfId) && $attachment->post_parent > 0) {
            $parentMime = get_post_mime_type($attachment->post_parent);
            if ($parentMime === 'application/pdf') {
                $sourcePdfId = $attachment->post_parent;
                $isThumbnail = true;
            }
        }

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
            }
            return $response;
        }

        // Only process PDFs
        if ($response['mime'] !== 'application/pdf') {
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

        // Get upload directory info
        $uploadDir = wp_upload_dir();
        $baseUrl = trailingslashit($uploadDir['baseurl']);
        $thumbnailFile = get_post_meta($thumbnailId, '_wp_attached_file', true);
        $thumbnailDir = trailingslashit(dirname($thumbnailFile));

        // Keep PDF URL for "File URL" field - DO NOT replace with thumbnail URL
        // $response['url'] stays as the PDF URL
        // Only set image dimensions from thumbnail for display purposes
        $response['width'] = $thumbnailMeta['width'] ?? 0;
        $response['height'] = $thumbnailMeta['height'] ?? 0;

        // Build sizes array from thumbnail sizes
        $sizes = [];
        $imageSizes = wp_get_registered_image_subsizes();

        // Add full size
        $sizes['full'] = [
            'url' => $thumbnailUrl,
            'width' => $thumbnailMeta['width'] ?? 0,
            'height' => $thumbnailMeta['height'] ?? 0,
            'orientation' => ($thumbnailMeta['width'] ?? 0) >= ($thumbnailMeta['height'] ?? 0) ? 'landscape' : 'portrait',
        ];

        // Add other sizes if available
        if (!empty($thumbnailMeta['sizes'])) {
            foreach ($thumbnailMeta['sizes'] as $sizeName => $sizeData) {
                $sizes[$sizeName] = [
                    'url' => $baseUrl . $thumbnailDir . $sizeData['file'],
                    'width' => $sizeData['width'],
                    'height' => $sizeData['height'],
                    'orientation' => $sizeData['width'] >= $sizeData['height'] ? 'landscape' : 'portrait',
                ];
            }
        }

        $response['sizes'] = $sizes;

        // Mark as having a PDF thumbnail
        $response['picThumbnailId'] = $thumbnailId;

        return $response;
    }

    /**
     * Filter REST API response for attachments (Block Editor)
     *
     * @param \WP_REST_Response $response REST response object
     * @param \WP_Post $post Attachment post object
     * @param \WP_REST_Request $request REST request object
     * @return \WP_REST_Response Modified response
     */
    public function filterRestAttachment(\WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request): \WP_REST_Response
    {
        // Check if this is a generated thumbnail - show source PDF URL instead
        $isThumbnail = get_post_meta($post->ID, '_rapls_pic_is_thumbnail', true);
        $sourcePdfId = get_post_meta($post->ID, '_rapls_pic_source_pdf', true);

        // Also check by post_parent for older thumbnails without meta
        if (empty($sourcePdfId) && $post->post_parent > 0) {
            $parentMime = get_post_mime_type($post->post_parent);
            if ($parentMime === 'application/pdf') {
                $sourcePdfId = $post->post_parent;
                $isThumbnail = true;
            }
        }

        if (!empty($isThumbnail) && !empty($sourcePdfId)) {
            $pdfUrl = wp_get_attachment_url((int) $sourcePdfId);
            if ($pdfUrl) {
                $data = $response->get_data();
                $data['source_url'] = $pdfUrl;
                $data['link'] = get_attachment_link((int) $sourcePdfId);
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

        // Get upload directory info
        $uploadDir = wp_upload_dir();
        $baseUrl = trailingslashit($uploadDir['baseurl']);
        $thumbnailFile = get_post_meta($thumbnailId, '_wp_attached_file', true);
        $thumbnailDir = trailingslashit(dirname($thumbnailFile));

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
        if (!empty($thumbnailMeta['sizes'])) {
            foreach ($thumbnailMeta['sizes'] as $sizeName => $sizeData) {
                $mediaDetails['sizes'][$sizeName] = [
                    'file' => $sizeData['file'],
                    'width' => $sizeData['width'],
                    'height' => $sizeData['height'],
                    'mime_type' => $sizeData['mime-type'] ?? get_post_mime_type($thumbnailId),
                    'source_url' => $baseUrl . $thumbnailDir . $sizeData['file'],
                ];
            }
        }

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
    public function filterMediaLibrary(\WP_Query $query): void
    {
        if (!is_admin()) {
            return;
        }

        // Only filter media library queries
        if ($query->get('post_type') !== 'attachment') {
            return;
        }

        // Check if we should hide thumbnails (setting + filter)
        $hideThumbnails = $this->settings->shouldHideGeneratedImages();
        $hideThumbnails = apply_filters('rapls_pdf_image_creator_hide_thumbnails_in_library', $hideThumbnails);
        if (!$hideThumbnails) {
            return;
        }

        // Exclude thumbnails created by this plugin
        $metaQuery = $query->get('meta_query') ?: [];
        $metaQuery[] = [
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
        $query->set('meta_query', $metaQuery);
    }

    /**
     * Add custom columns to media library
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addMediaColumns(array $columns): array
    {
        $columns['rapls_pic_thumbnail'] = __('PDF Thumbnail', 'rapls-pdf-image-creator');
        return $columns;
    }

    /**
     * Render custom column content
     *
     * @param string $columnName Column name
     * @param int $postId Post ID
     */
    public function renderMediaColumn(string $columnName, int $postId): void
    {
        if ($columnName !== 'rapls_pic_thumbnail') {
            return;
        }

        // Only for PDFs
        $mimeType = get_post_mime_type($postId);
        if ($mimeType !== 'application/pdf') {
            echo '—';
            return;
        }

        if ($this->generator->hasThumbnail($postId)) {
            echo '<span class="pic-status pic-status-yes" title="' . esc_attr__('Thumbnail generated', 'rapls-pdf-image-creator') . '">✓</span>';
        } else {
            echo '<span class="pic-status pic-status-no" title="' . esc_attr__('No thumbnail', 'rapls-pdf-image-creator') . '">✗</span>';
        }
    }

    /**
     * Add row actions to media library
     *
     * @param array $actions Existing actions
     * @param \WP_Post $post Post object
     * @return array Modified actions
     */
    public function addRowActions(array $actions, \WP_Post $post): array
    {
        // Only for PDFs
        if (get_post_mime_type($post->ID) !== 'application/pdf') {
            return $actions;
        }

        // Check permissions
        if (!current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

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

        // Redirect back with message
        $redirectUrl = admin_url('upload.php');
        if ($result) {
            $redirectUrl = add_query_arg('rapls_pic_generated', '1', $redirectUrl);
        } else {
            $redirectUrl = add_query_arg('rapls_pic_error', '1', $redirectUrl);
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * AJAX handler for regenerating thumbnail
     */
    public function ajaxRegenerateThumbnail(): void
    {
        check_ajax_referer('rapls_pic_admin', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'rapls-pdf-image-creator')]);
        }

        $attachmentId = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachmentId) {
            wp_send_json_error(['message' => __('Invalid attachment ID.', 'rapls-pdf-image-creator')]);
        }

        $force = !empty($_POST['force']);
        $result = $this->generator->generate($attachmentId, $force);

        if ($result) {
            wp_send_json_success([
                'message' => __('Thumbnail generated successfully.', 'rapls-pdf-image-creator'),
                'thumbnail_id' => $result,
                'thumbnail_url' => $this->generator->getThumbnailUrl($attachmentId, 'thumbnail'),
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to generate thumbnail.', 'rapls-pdf-image-creator'),
            ]);
        }
    }

    /**
     * Allow PDFs with thumbnails in image selection queries and hide generated thumbnails
     *
     * @param array $query Query arguments
     * @return array Modified query arguments
     */
    public function allowPdfsInImageSelection(array $query): array
    {
        // Hide generated thumbnails from AJAX media queries
        $hideThumbnails = $this->settings->shouldHideGeneratedImages();
        $hideThumbnails = apply_filters('rapls_pdf_image_creator_hide_thumbnails_in_library', $hideThumbnails);

        if ($hideThumbnails) {
            $metaQuery = isset($query['meta_query']) ? $query['meta_query'] : [];
            $metaQuery[] = [
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
            $query['meta_query'] = $metaQuery;
        }

        // Check if this is an image-only query
        if (!isset($query['post_mime_type'])) {
            return $query;
        }

        $mimeType = $query['post_mime_type'];

        // If querying for images, also include PDFs
        if ($mimeType === 'image' || (is_array($mimeType) && in_array('image', $mimeType, true))) {
            // Include PDFs that have thumbnails
            if (is_array($mimeType)) {
                $query['post_mime_type'][] = 'application/pdf';
            } else {
                $query['post_mime_type'] = ['image', 'application/pdf'];
            }
        }

        return $query;
    }

    /**
     * Enqueue block editor assets
     */
    public function enqueueBlockEditorAssets(): void
    {
        wp_enqueue_script(
            'pic-block-editor',
            RAPLS_PIC_PLUGIN_URL . 'admin/js/block-editor.js',
            ['wp-blocks', 'wp-dom-ready', 'wp-edit-post'],
            RAPLS_PIC_VERSION,
            true
        );
    }

    /**
     * Filter attachment image attributes for PDFs
     *
     * @param array $attr Image attributes
     * @param \WP_Post $attachment Attachment post object
     * @param string|int[] $size Image size
     * @return array Modified attributes
     */
    public function filterAttachmentImageAttributes(array $attr, \WP_Post $attachment, $size): array
    {
        // Check if display thumbnail icon is enabled
        if (!$this->settings->shouldDisplayThumbnailIcon()) {
            return $attr;
        }

        // Only process PDFs
        if (get_post_mime_type($attachment->ID) !== 'application/pdf') {
            return $attr;
        }

        // Get thumbnail
        $thumbnailId = $this->generator->getThumbnailId($attachment->ID);
        if (!$thumbnailId) {
            return $attr;
        }

        // Get thumbnail URL
        $sizeToUse = is_array($size) ? 'thumbnail' : $size;
        $thumbnailSrc = wp_get_attachment_image_src($thumbnailId, $sizeToUse);
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
     * @param string $icon Icon URL
     * @param string $mime MIME type
     * @param int $postId Post ID
     * @return string Modified icon URL
     */
    public function filterMimeTypeIcon(string $icon, string $mime, int $postId): string
    {
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
