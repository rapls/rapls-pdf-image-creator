<?php
/**
 * Admin Handler
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Handles admin functionality
 */
final class Admin
{
    /**
     * Settings page slug
     */
    public const PAGE_SLUG = 'rapls-pdf-image-creator';

    /**
     * User meta recording that the colour update notice was dismissed
     */
    private const COLOR_NOTICE_META = 'rapls_pic_color_notice_dismissed';

    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Generator instance
     */
    private Generator $generator;

    /**
     * Constructor
     *
     * @param Settings $settings Settings manager
     * @param Generator $generator Generator instance
     */
    public function __construct(Settings $settings, Generator $generator)
    {
        $this->settings = $settings;
        $this->generator = $generator;
    }

    /**
     * Initialize admin hooks
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'maybeDismissColorNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'showAdminNotices']);
    }

    /**
     * Add menu page
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('Rapls PDF Image Creator', 'rapls-pdf-image-creator'),
            __('Rapls PDF Image Creator', 'rapls-pdf-image-creator'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register settings
     */
    public function registerSettings(): void
    {
        register_setting(
            'rapls_pic_settings_group',
            Settings::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
            ]
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Raw input
     * @return array Sanitized input
     */
    public function sanitizeSettings(array $input): array
    {
        $sanitized = [];

        // Max dimensions
        $sanitized['max_width'] = max(100, min(4096, absint($input['max_width'] ?? 1024)));
        $sanitized['max_height'] = max(100, min(4096, absint($input['max_height'] ?? 1024)));

        // Quality
        $sanitized['quality'] = max(10, min(100, absint($input['quality'] ?? 90)));

        // Format
        $validFormats = ['jpeg', 'png', 'webp'];
        $sanitized['format'] = in_array($input['format'] ?? 'jpeg', $validFormats, true)
            ? $input['format']
            : 'jpeg';

        // Background color
        $validBgColors = ['white', 'black', 'transparent'];
        $sanitized['bgcolor'] = in_array($input['bgcolor'] ?? 'white', $validBgColors, true)
            ? $input['bgcolor']
            : 'white';

        // Page number
        $sanitized['page'] = max(0, absint($input['page'] ?? 0));

        // Booleans
        $sanitized['auto_generate'] = !empty($input['auto_generate']);
        $sanitized['set_featured'] = !empty($input['set_featured']);

        // Insert type
        $validInsertTypes = ['image', 'title', 'custom'];
        $sanitized['insert_type'] = in_array($input['insert_type'] ?? 'image', $validInsertTypes, true)
            ? $input['insert_type']
            : 'image';

        // Insert size
        $validSizes = array_merge(get_intermediate_image_sizes(), ['full']);
        $sanitized['insert_size'] = in_array($input['insert_size'] ?? 'medium', $validSizes, true)
            ? $input['insert_size']
            : 'medium';

        // Insert link
        $validLinks = ['file', 'attachment', 'none'];
        $sanitized['insert_link'] = in_array($input['insert_link'] ?? 'file', $validLinks, true)
            ? $input['insert_link']
            : 'file';

        // Custom HTML template
        $sanitized['custom_html'] = wp_kses_post($input['custom_html'] ?? '');

        // Display settings
        $sanitized['display_thumbnail_icon'] = !empty($input['display_thumbnail_icon']);
        $sanitized['hide_generated_images'] = !empty($input['hide_generated_images']);
        $sanitized['keep_on_uninstall'] = !empty($input['keep_on_uninstall']);

        return $sanitized;
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page
     */
    public function enqueueAssets(string $hook): void
    {
        // Only on our settings page or media library
        if ($hook !== 'settings_page_' . self::PAGE_SLUG && $hook !== 'upload.php') {
            return;
        }

        wp_enqueue_style(
            'rapls-pic-admin',
            RAPLS_PIC_PLUGIN_URL . 'admin/css/admin.css',
            [],
            RAPLS_PIC_VERSION
        );

        wp_enqueue_script(
            'rapls-pic-admin',
            RAPLS_PIC_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            RAPLS_PIC_VERSION,
            true
        );

        wp_localize_script('rapls-pic-admin', 'raplsPicAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rapls_pic_admin'),
            'i18n' => [
                'processing' => __('Processing...', 'rapls-pdf-image-creator'),
                'complete' => __('Complete!', 'rapls-pdf-image-creator'),
                'error' => __('An error occurred.', 'rapls-pdf-image-creator'),
                'confirmBulk' => __('Start bulk generation? This may take a while.', 'rapls-pdf-image-creator'),
                /* translators: %1$d: current number, %2$d: total number */
                'generating' => __('Generating thumbnail %1$d of %2$d...', 'rapls-pdf-image-creator'),
            ],
        ]);
    }

    /**
     * Handle dismissal of the colour update notice
     */
    public function maybeDismissColorNotice(): void
    {
        if (!isset($_GET['rapls_pic_dismiss_color_notice'])) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rapls_pic_dismiss_color_notice')) {
            return;
        }

        update_user_meta(get_current_user_id(), self::COLOR_NOTICE_META, '1');
    }

    /**
     * Build the dismissal URL for the colour update notice
     *
     * @param bool $toBulkTab Whether to land on the Bulk Generate tab
     */
    private function getColorNoticeDismissUrl(bool $toBulkTab): string
    {
        $url = wp_nonce_url(
            add_query_arg(
                'rapls_pic_dismiss_color_notice',
                '1',
                admin_url('options-general.php?page=' . self::PAGE_SLUG)
            ),
            'rapls_pic_dismiss_color_notice'
        );

        return $toBulkTab ? $url . '#tab-bulk' : $url;
    }

    /**
     * Show the one-time notice about the colour conversion change
     *
     * Both links dismiss the notice for good; the X only hides it for this
     * page view, which is the usual WordPress behaviour.
     */
    private function showColorUpdateNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ('1' !== get_option(Plugin::COLOR_NOTICE_OPTION)) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::COLOR_NOTICE_META, true)) {
            return;
        }

        echo '<div class="notice notice-info">';
        echo '<p><strong>' . esc_html__('Rapls PDF Image Creator:', 'rapls-pdf-image-creator') . '</strong> ';
        echo esc_html__('Color conversion has been improved. Thumbnails generated before this update keep their old colors.', 'rapls-pdf-image-creator');
        echo '</p><p>';
        echo '<a class="button button-primary" href="' . esc_url($this->getColorNoticeDismissUrl(true)) . '">';
        echo esc_html__('Regenerate thumbnails', 'rapls-pdf-image-creator');
        echo '</a> ';
        echo '<a class="button" href="' . esc_url($this->getColorNoticeDismissUrl(false)) . '">';
        echo esc_html__('Dismiss', 'rapls-pdf-image-creator');
        echo '</a>';
        echo '</p></div>';
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices(): void
    {
        $this->showColorUpdateNotice();

        // Success notice
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
        if (isset($_GET['rapls_pic_generated'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__('PDF thumbnail generated successfully.', 'rapls-pdf-image-creator') . '</p>';
            echo '</div>';
        }

        // Error notice
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
        if (isset($_GET['rapls_pic_error'])) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . esc_html__('Failed to generate PDF thumbnail.', 'rapls-pdf-image-creator') . '</p>';
            echo '</div>';
        }

        // Show warning if no engine available
        $screen = get_current_screen();
        if ($screen && ($screen->id === 'settings_page_' . self::PAGE_SLUG || $screen->id === 'upload')) {
            if (!$this->generator->getAvailableEngine()) {
                echo '<div class="notice notice-error">';
                echo '<p><strong>' . esc_html__('Rapls PDF Image Creator:', 'rapls-pdf-image-creator') . '</strong> ';
                echo esc_html__('ImageMagick (Imagick PHP extension) with PDF support is required but not available. Please contact your hosting provider to enable it.', 'rapls-pdf-image-creator');
                echo '</p></div>';
            }
        }
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $config = $this->settings->get();
        $capabilities = $this->generator->checkCapabilities();

        include RAPLS_PIC_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
}
