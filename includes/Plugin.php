<?php
/**
 * Main Plugin Class
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Main plugin class - Singleton pattern
 */
final class Plugin
{
    /**
     * Plugin instance
     */
    private static ?Plugin $instance = null;

    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Thumbnail generator
     */
    private Generator $generator;

    /**
     * Admin handler
     */
    private ?Admin $admin = null;

    /**
     * Media library integration
     */
    private MediaLibrary $mediaLibrary;

    /**
     * PDF inserter
     */
    private Inserter $inserter;

    /**
     * Bulk processor
     */
    private BulkProcessor $bulkProcessor;

    /**
     * Get plugin instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->settings = new Settings();
        $this->generator = new Generator($this->settings);
        $this->mediaLibrary = new MediaLibrary($this->generator, $this->settings);
        $this->inserter = new Inserter($this->generator, $this->settings);
        $this->bulkProcessor = new BulkProcessor($this->generator, $this->settings);
    }

    /**
     * Initialize the plugin
     */
    public function init(): void
    {
        // Initialize components
        $this->mediaLibrary->init();
        $this->inserter->init();

        // Register AJAX handlers early (needed for admin-ajax.php requests)
        $this->bulkProcessor->init();

        // Admin only (non-AJAX)
        if (is_admin()) {
            $this->admin = new Admin($this->settings, $this->generator);
            $this->admin->init();
        }

        // Register shortcodes
        $this->registerShortcodes();
    }

    /**
     * Get settings manager
     */
    public function getSettings(): Settings
    {
        return $this->settings;
    }

    /**
     * Get generator
     */
    public function getGenerator(): Generator
    {
        return $this->generator;
    }

    /**
     * Register shortcodes
     */
    private function registerShortcodes(): void
    {
        // [rapls_pdf_thumbnail id="123" size="medium"]
        add_shortcode('rapls_pdf_thumbnail', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'size' => 'thumbnail',
                'class' => '',
            ], $atts, 'rapls_pdf_thumbnail');

            $id = absint($atts['id']);
            if (!$id) {
                return '';
            }

            return $this->generator->getThumbnailImage($id, $atts['size'], [
                'class' => sanitize_html_class($atts['class']),
            ]);
        });

        // [rapls_pdf_thumbnail_url id="123" size="medium"]
        add_shortcode('rapls_pdf_thumbnail_url', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'size' => 'thumbnail',
            ], $atts, 'rapls_pdf_thumbnail_url');

            $id = absint($atts['id']);
            if (!$id) {
                return '';
            }

            return esc_url($this->generator->getThumbnailUrl($id, $atts['size']) ?? '');
        });

        // [rapls_pdf_clickable_thumbnail id="123" size="medium"]
        add_shortcode('rapls_pdf_clickable_thumbnail', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'size' => 'thumbnail',
                'class' => '',
                'target' => '_blank',
            ], $atts, 'rapls_pdf_clickable_thumbnail');

            $id = absint($atts['id']);
            if (!$id) {
                return '';
            }

            $pdfUrl = wp_get_attachment_url($id);
            if (!$pdfUrl) {
                return '';
            }

            $image = $this->generator->getThumbnailImage($id, $atts['size'], [
                'class' => sanitize_html_class($atts['class']),
            ]);

            if (!$image) {
                return '';
            }

            return sprintf(
                '<a href="%s" target="%s" rel="noopener noreferrer">%s</a>',
                esc_url($pdfUrl),
                esc_attr($atts['target']),
                $image
            );
        });

        // [rapls_pdf_download_link id="123" show_thumbnail="true"]
        add_shortcode('rapls_pdf_download_link', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'show_thumbnail' => 'true',
                'size' => 'thumbnail',
                'text' => '',
                'class' => 'rapls-pic-download-link',
            ], $atts, 'rapls_pdf_download_link');

            $id = absint($atts['id']);
            if (!$id) {
                return '';
            }

            $pdfUrl = wp_get_attachment_url($id);
            if (!$pdfUrl) {
                return '';
            }

            $content = '';
            if ($atts['show_thumbnail'] === 'true') {
                $content .= $this->generator->getThumbnailImage($id, $atts['size']);
            }

            $text = $atts['text'] ?: get_the_title($id);
            $content .= '<span class="rapls-pic-download-text">' . esc_html($text) . '</span>';

            return sprintf(
                '<a href="%s" class="%s" download>%s</a>',
                esc_url($pdfUrl),
                esc_attr($atts['class']),
                $content
            );
        });
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
