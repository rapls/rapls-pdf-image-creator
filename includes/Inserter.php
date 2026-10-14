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

        // Not on wp_prepare_attachment_for_js: the insert settings and the
        // whole insert HTML were added to every PDF in every media-modal
        // query, and no script read either (review after 1.4.31). The HTML
        // is made when "Insert into post" is pressed, above.

        // The media window's Link To and Size start at the settings.
        add_action('wp_enqueue_media', [$this, 'enqueueInsertLink']);
    }

    /**
     * Start the media window's Link To and Size at the settings, for PDFs
     *
     * The window's choice is what linkFor() and sizeFor() follow; this makes
     * the setting that choice until someone changes it there (review of
     * 1.4.33). The script also sends the window's Size, which core sends
     * for images only (review of 1.4.34), so it needs media-editor.
     */
    public function enqueueInsertLink(): void
    {
        wp_enqueue_script(
            'pic-insert-link',
            RAPLS_PIC_PLUGIN_URL . 'admin/js/insert-link.js',
            ['media-views', 'media-editor'],
            RAPLS_PIC_VERSION,
            true
        );
        // The sizes the window's Size offers: core lists only these, and
        // turns a value it does not list into whatever it shows selected. An
        // "Insert size" of medium_large or a theme's size became "medium"
        // that way, where 1.4.20 inserted the setting (review of 1.4.35).
        // The labels are core's to show; only the names are read here.
        $offered = apply_filters('image_size_names_choose', [
            'thumbnail' => 'Thumbnail',
            'medium' => 'Medium',
            'large' => 'Large',
            'full' => 'Full Size',
        ]);
        wp_localize_script('pic-insert-link', 'raplsPicInsertLink', [
            'link' => $this->settings->getInsertLink(),
            'size' => $this->settings->getInsertSize(),
            'sizes' => array_values(array_map('strval', array_keys(is_array($offered) ? $offered : []))),
        ]);
    }

    /**
     * Filter media being sent to editor
     *
     * Untyped: another plugin's filter returning a non-string, or a call
     * with fewer arguments, was a fatal error (review of 1.4.34).
     *
     * @param mixed $html HTML to be sent to editor
     * @param mixed $attachmentId Attachment ID
     * @param mixed $attachment Attachment data, as the media window sent it
     *                          ('url' is its Link To, 'image-size' its Size;
     *                          see linkFor() and sizeFor())
     * @return mixed Modified HTML
     */
    public function filterMediaSendToEditor($html, $attachmentId = 0, $attachment = [])
    {
        if (!is_numeric($attachmentId)) {
            return $html;
        }
        $attachmentId = (int) $attachmentId;
        $attachment = is_array($attachment) ? $attachment : [];

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
        $insertSize = $this->sizeFor($attachment);

        // Get PDF data
        // Cast: either can be false (a file gone, a permalink filter), and
        // false reached string parameters below -- a TypeError in this
        // strict file (review of 1.4.32).
        $pdfUrl = (string) wp_get_attachment_url($pdfId);
        $pdfTitle = (string) get_the_title($pdfId);
        $attachmentUrl = (string) get_attachment_link($pdfId);
        $href = $this->linkFor($attachment, $pdfUrl, $attachmentUrl);

        // Get thumbnail data
        $thumbnailId = $this->generator->getThumbnailId($pdfId);
        $thumbnailUrl = $thumbnailId ? $this->generator->getThumbnailUrl($pdfId, $insertSize) : '';

        switch ($insertType) {
            case 'title':
                return $this->generateTitleHtml($pdfId, $pdfTitle, $href);

            case 'custom':
                return $this->generateCustomHtml($pdfId, $pdfUrl, $pdfTitle, $attachmentUrl, $thumbnailUrl, $href, $insertSize);

            case 'image':
            default:
                return $this->generateImageHtml($pdfId, $pdfTitle, $thumbnailUrl, $insertSize, $href);
        }
    }

    /**
     * Where the inserted HTML links to; '' for no link
     *
     * The media window's own "Link To" when it said one. Its choice was
     * ignored, and the PDF linked to wherever this plugin's setting said
     * whatever was picked there (review of 1.4.33). The window starts at
     * the setting (admin/js/insert-link.js), so the setting still decides
     * when nobody changes it there. Core sends the link as
     * 'url', and leaves 'url' out for "None"; its 'id' says the request came
     * from the media window. Without either -- another caller -- the "Link
     * To" setting decides, as before.
     *
     * @param array<string, mixed> $attachment What came with the request
     */
    private function linkFor(array $attachment, string $pdfUrl, string $attachmentUrl): string
    {
        if (array_key_exists('url', $attachment)) {
            return is_string($attachment['url']) ? $attachment['url'] : '';
        }

        if (isset($attachment['id'])) {
            return '';
        }

        switch ($this->settings->getInsertLink()) {
            case 'none':
                return '';
            case 'attachment':
                return $attachmentUrl;
            default:
                return $pdfUrl;
        }
    }

    /**
     * The image size to insert
     *
     * The media window's Size when it sent one that exists. The window
     * showed the control for a PDF and its choice was dropped, so the
     * "Insert size" setting always decided (review of 1.4.34);
     * admin/js/insert-link.js sends it as 'image-size', core's name for it
     * on an image. Without it -- another caller -- the setting decides.
     *
     * @param array<string, mixed> $attachment What came with the request
     */
    private function sizeFor(array $attachment): string
    {
        $size = $attachment['image-size'] ?? null;
        if (is_string($size) && in_array($size, array_merge((array) get_intermediate_image_sizes(), ['full']), true)) {
            return $size;
        }

        return $this->settings->getInsertSize();
    }

    /**
     * Generate title-only HTML
     *
     * @param int $pdfId PDF attachment ID
     * @param string $pdfTitle PDF title
     * @param string $href Where it links to; '' for no link
     * @return string Generated HTML
     */
    private function generateTitleHtml(int $pdfId, string $pdfTitle, string $href): string
    {
        if ('' === $href) {
            return esc_html($pdfTitle);
        }

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
     * @param string $pdfTitle PDF title
     * @param string $thumbnailUrl Thumbnail URL
     * @param string $insertSize Image size
     * @param string $href Where it links to; '' for no link
     * @return string Generated HTML
     */
    private function generateImageHtml(int $pdfId, string $pdfTitle, string $thumbnailUrl, string $insertSize, string $href): string
    {
        if (!$thumbnailUrl) {
            return $this->generateTitleHtml($pdfId, $pdfTitle, $href);
        }

        // "wp-image-N", as core's own insert writes it: WordPress adds srcset
        // and sizes to an image in a post by that class, and it names the
        // image by ID for a regeneration to see that a post shows it, should
        // the address be rewritten (review after 1.4.37).
        $thumbnailId = (int) $this->generator->getThumbnailId($pdfId);
        $classes = 'rapls-pic-pdf-thumbnail alignnone size-' . $insertSize . ($thumbnailId > 0 ? ' wp-image-' . $thumbnailId : '');

        // Get image HTML
        $imageHtml = $this->generator->getThumbnailImage($pdfId, $insertSize, [
            'class' => $classes,
            'alt' => $pdfTitle,
        ]);

        if (empty($imageHtml)) {
            $imageHtml = sprintf(
                '<img src="%s" alt="%s" class="%s">',
                esc_url($thumbnailUrl),
                esc_attr($pdfTitle),
                esc_attr($classes)
            );
        }

        if ('' === $href) {
            return $imageHtml;
        }

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
     * @param string $href Where the fallback links to; '' for no link
     * @param string $insertSize Image size for the fallback
     * @return string Generated HTML
     */
    private function generateCustomHtml(int $pdfId, string $pdfUrl, string $pdfTitle, string $attachmentUrl, string $thumbnailUrl, string $href, string $insertSize): string
    {
        $template = $this->settings->getCustomHtml();

        if (empty($template)) {
            // Fall back to image if no template set
            return $this->generateImageHtml(
                $pdfId,
                $pdfTitle,
                $thumbnailUrl,
                $insertSize,
                $href
            );
        }

        // Replace placeholders. Escaped for where a template puts them --
        // inside an attribute, as the example does: a title with a quote in
        // it closed the attribute and broke the markup, which wp_kses_post()
        // below does not repair (review after 1.4.31). In text, the escaped
        // title reads the same.
        $replacements = [
            '%PDF_URL%' => esc_url($pdfUrl),
            '%THUMBNAIL_URL%' => esc_url($thumbnailUrl),
            '%TITLE%' => esc_attr($pdfTitle),
            '%ID%' => (string) $pdfId,
            '%ATTACHMENT_URL%' => esc_url($attachmentUrl),
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
}
