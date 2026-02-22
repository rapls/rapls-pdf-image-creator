<?php
/**
 * PDF Inserter
 *
 * Handles PDF insertion into content with configured settings.
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Handles PDF insertion with thumbnails
 */
final class Inserter
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
        // Filter media send to editor
        add_filter('media_send_to_editor', [$this, 'filterMediaSendToEditor'], 20, 3);

        // Add insert settings to attachment JS data
        add_filter('wp_prepare_attachment_for_js', [$this, 'addInsertSettingsToJs'], 20, 3);
    }

    /**
     * Filter media being sent to editor
     *
     * @param string $html HTML to be sent to editor
     * @param int $attachmentId Attachment ID
     * @param array $attachment Attachment data
     * @return string Modified HTML
     */
    public function filterMediaSendToEditor(string $html, int $attachmentId, array $attachment): string
    {
        // Only process PDFs
        $mimeType = get_post_mime_type($attachmentId);
        if ($mimeType !== 'application/pdf') {
            return $html;
        }

        // Check if thumbnail exists
        if (!$this->generator->hasThumbnail($attachmentId)) {
            return $html;
        }

        return $this->generateInsertHtml($attachmentId, $attachment);
    }

    /**
     * Generate HTML for PDF insertion
     *
     * @param int $pdfId PDF attachment ID
     * @param array $attachment Attachment data
     * @return string Generated HTML
     */
    public function generateInsertHtml(int $pdfId, array $attachment = []): string
    {
        $insertType = $this->settings->getInsertType();
        $insertSize = $this->settings->getInsertSize();
        $insertLink = $this->settings->getInsertLink();

        // Get PDF data
        $pdfUrl = wp_get_attachment_url($pdfId);
        $pdfTitle = get_the_title($pdfId);
        $attachmentUrl = get_attachment_link($pdfId);

        // Get thumbnail data
        $thumbnailId = $this->generator->getThumbnailId($pdfId);
        $thumbnailUrl = $thumbnailId ? $this->generator->getThumbnailUrl($pdfId, $insertSize) : '';

        switch ($insertType) {
            case 'title':
                return $this->generateTitleHtml($pdfId, $pdfUrl, $pdfTitle, $attachmentUrl, $insertLink);

            case 'custom':
                return $this->generateCustomHtml($pdfId, $pdfUrl, $pdfTitle, $attachmentUrl, $thumbnailUrl);

            case 'image':
            default:
                return $this->generateImageHtml($pdfId, $pdfUrl, $pdfTitle, $attachmentUrl, $thumbnailUrl, $insertSize, $insertLink);
        }
    }

    /**
     * Generate title-only HTML
     *
     * @param int $pdfId PDF attachment ID
     * @param string $pdfUrl PDF URL
     * @param string $pdfTitle PDF title
     * @param string $attachmentUrl Attachment page URL
     * @param string $insertLink Link type
     * @return string Generated HTML
     */
    private function generateTitleHtml(int $pdfId, string $pdfUrl, string $pdfTitle, string $attachmentUrl, string $insertLink): string
    {
        if ($insertLink === 'none') {
            return esc_html($pdfTitle);
        }

        $href = $insertLink === 'attachment' ? $attachmentUrl : $pdfUrl;

        return sprintf(
            '<a href="%s" class="rapls-pic-pdf-link" data-pdf-id="%d">%s</a>',
            esc_url($href),
            $pdfId,
            esc_html($pdfTitle)
        );
    }

    /**
     * Generate image HTML
     *
     * @param int $pdfId PDF attachment ID
     * @param string $pdfUrl PDF URL
     * @param string $pdfTitle PDF title
     * @param string $attachmentUrl Attachment page URL
     * @param string $thumbnailUrl Thumbnail URL
     * @param string $insertSize Image size
     * @param string $insertLink Link type
     * @return string Generated HTML
     */
    private function generateImageHtml(int $pdfId, string $pdfUrl, string $pdfTitle, string $attachmentUrl, string $thumbnailUrl, string $insertSize, string $insertLink): string
    {
        if (!$thumbnailUrl) {
            return $this->generateTitleHtml($pdfId, $pdfUrl, $pdfTitle, $attachmentUrl, $insertLink);
        }

        // Get image HTML
        $imageHtml = $this->generator->getThumbnailImage($pdfId, $insertSize, [
            'class' => 'rapls-pic-pdf-thumbnail alignnone size-' . $insertSize,
            'alt' => $pdfTitle,
        ]);

        if (empty($imageHtml)) {
            $imageHtml = sprintf(
                '<img src="%s" alt="%s" class="rapls-pic-pdf-thumbnail alignnone size-%s">',
                esc_url($thumbnailUrl),
                esc_attr($pdfTitle),
                esc_attr($insertSize)
            );
        }

        if ($insertLink === 'none') {
            return $imageHtml;
        }

        $href = $insertLink === 'attachment' ? $attachmentUrl : $pdfUrl;

        return sprintf(
            '<a href="%s" class="rapls-pic-pdf-link" data-pdf-id="%d">%s</a>',
            esc_url($href),
            $pdfId,
            $imageHtml
        );
    }

    /**
     * Generate custom HTML from template
     *
     * @param int $pdfId PDF attachment ID
     * @param string $pdfUrl PDF URL
     * @param string $pdfTitle PDF title
     * @param string $attachmentUrl Attachment page URL
     * @param string $thumbnailUrl Thumbnail URL
     * @return string Generated HTML
     */
    private function generateCustomHtml(int $pdfId, string $pdfUrl, string $pdfTitle, string $attachmentUrl, string $thumbnailUrl): string
    {
        $template = $this->settings->getCustomHtml();

        if (empty($template)) {
            // Fall back to image if no template set
            return $this->generateImageHtml(
                $pdfId,
                $pdfUrl,
                $pdfTitle,
                $attachmentUrl,
                $thumbnailUrl,
                $this->settings->getInsertSize(),
                $this->settings->getInsertLink()
            );
        }

        // Replace placeholders
        $replacements = [
            '%PDF_URL%' => $pdfUrl,
            '%THUMBNAIL_URL%' => $thumbnailUrl,
            '%TITLE%' => $pdfTitle,
            '%ID%' => (string) $pdfId,
            '%ATTACHMENT_URL%' => $attachmentUrl,
        ];

        $html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template
        );

        /**
         * Filter the custom HTML output
         *
         * @param string $html Generated HTML
         * @param int $pdfId PDF attachment ID
         * @param array $replacements Replacement values
         */
        $html = apply_filters('rapls_pdf_image_creator_custom_insert_html', $html, $pdfId, $replacements);

        // Sanitize final HTML output
        return wp_kses_post($html);
    }

    /**
     * Add insert settings to attachment JS data
     *
     * @param array $response Attachment response data
     * @param \WP_Post $attachment Attachment post object
     * @param array|bool $meta Attachment meta data
     * @return array Modified response
     */
    public function addInsertSettingsToJs(array $response, \WP_Post $attachment, $meta): array
    {
        // Only process PDFs
        if ($response['mime'] !== 'application/pdf') {
            return $response;
        }

        // Add insert settings
        $response['picInsertSettings'] = [
            'type' => $this->settings->getInsertType(),
            'size' => $this->settings->getInsertSize(),
            'link' => $this->settings->getInsertLink(),
            'hasThumbnail' => $this->generator->hasThumbnail($attachment->ID),
        ];

        // Pre-generate insert HTML
        if ($this->generator->hasThumbnail($attachment->ID)) {
            $response['picInsertHtml'] = $this->generateInsertHtml($attachment->ID);
        }

        return $response;
    }
}
