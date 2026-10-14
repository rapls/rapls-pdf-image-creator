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
     * User meta recording which "no engine" notice was dismissed
     *
     * Holds the status code, not a flag: dismissing "Imagick is not
     * installed" should not also silence "policy.xml forbids PDFs" if the
     * server later changes underneath them.
     */
    private const ENGINE_NOTICE_META = 'rapls_pic_engine_notice_dismissed';

    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Generator instance
     */
    private Generator $generator;

    /**
     * Whether a WebP thumbnail can be made and kept here
     *
     * WordPress accepts WebP images from 5.8 -- before it, the generated file
     * had no MIME type and failed as "could not be added to the Media
     * Library" -- and ImageMagick needs a WebP encoder. The setting offered it
     * on every server (review after 1.4.31).
     */
    public static function webpWorks(): bool
    {
        $types = function_exists('wp_get_mime_types') ? (array) wp_get_mime_types() : [];

        if (!in_array('image/webp', $types, true)) {
            return false;
        }

        try {
            return class_exists('\Imagick') && [] !== (array) \Imagick::queryFormats('WEBP');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the Status tab's "Support This Plugin" box is shown
     *
     * Only while this server can make thumbnails. It sat under the failure
     * notices too, asking for a coffee and a review beside "this server
     * cannot render PDFs" (review of 1.4.34) -- the same reason the review
     * request waits for a working server. The same two tests as the review
     * request: the Status tab's answer, and the last upload not having found
     * the server unable (review of 1.4.35).
     *
     * @param mixed $capabilities Generator::checkCapabilities()
     */
    public static function showsSupportBox($capabilities): bool
    {
        return is_array($capabilities) && is_array($capabilities['availability'] ?? null)
            && 'ok' === ($capabilities['availability']['code'] ?? '')
            && false === get_option(Generator::UNAVAILABLE_OPTION, false);
    }

    /**
     * Whether the Status tab's CMYK box belongs under the CMYK row
     *
     * Only where the row does not say it works: tested broken, or not
     * tested. It came with the row on every ImageMagick 6 server, under
     * "Working on this server (tested)" too -- the version-number warning
     * 1.3.2 meant to end (review after 1.4.31).
     *
     * @param mixed $row The cmyk_pdf requirement, or null when there is none.
     */
    public static function showsCmykWarning($row): bool
    {
        return is_array($row) && empty($row['status']);
    }

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
        // The notices' own parameters come out of the address once shown, so
        // a reload does not say them again (review after 1.4.31).
        add_filter('removable_query_args', [$this, 'removableQueryArgs']);

        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_init', [$this, 'maybeDismissColorNotice']);
        add_action('admin_init', [$this, 'maybeDismissEngineNotice']);
        add_action('admin_init', [$this, 'maybeRecheckProbes']);
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

    /** A field only the settings form sends, so a save can tell it from code's. */
    public const FORM_MARKER = '_rapls_pic_form';

    /** The settings that are checkboxes on the form. */
    private const CHECKBOXES = ['auto_generate', 'set_featured', 'display_thumbnail_icon', 'hide_generated_images', 'keep_on_uninstall'];

    /**
     * Sanitize settings
     *
     * @param mixed $input What was submitted, or what update_option() was given.
     * @return array Sanitized input
     */
    public function sanitizeSettings($input): array
    {
        // Not an array -- "wp option update rapls_pic_settings ''", another
        // plugin -- was a TypeError here, under the save itself. The settings
        // as they are stay (review after 1.4.31).
        if (!is_array($input)) {
            return $this->settings->get(true);
        }

        // A key not given keeps the value it has. From the settings form,
        // whose marker says so, a checkbox not given is one unticked -- a
        // browser sends nothing for it. From anywhere else, a save of a
        // few keys turned every checkbox it left out off (review after
        // 1.4.37).
        $current = $this->settings->get(true);
        $fromForm = !empty($input[self::FORM_MARKER]);
        unset($input[self::FORM_MARKER]);
        $input += $fromForm ? array_diff_key($current, array_flip(self::CHECKBOXES)) : $current;

        $sanitized = [];

        // Max dimensions
        $sanitized['max_width'] = max(100, min(4096, absint($input['max_width'] ?? 1024)));
        $sanitized['max_height'] = max(100, min(4096, absint($input['max_height'] ?? 1024)));

        // Rendering resolution (DPI)
        $sanitized['resolution'] = max(72, min(600, absint($input['resolution'] ?? 150)));

        // Quality
        $sanitized['quality'] = max(10, min(100, absint($input['quality'] ?? 90)));

        // Format
        $validFormats = ['jpeg', 'png', 'webp'];
        $sanitized['format'] = self::oneOf($input['format'] ?? 'jpeg', $validFormats, 'jpeg');

        // Background color
        $validBgColors = ['white', 'black', 'transparent'];
        $sanitized['bgcolor'] = self::oneOf($input['bgcolor'] ?? 'white', $validBgColors, 'white');

        // Page number
        $sanitized['page'] = max(0, absint($input['page'] ?? 0));

        // Booleans
        $sanitized['auto_generate'] = !empty($input['auto_generate']);
        $sanitized['set_featured'] = !empty($input['set_featured']);

        // Insert type
        $validInsertTypes = ['image', 'title', 'custom'];
        $sanitized['insert_type'] = self::oneOf($input['insert_type'] ?? 'image', $validInsertTypes, 'image');

        // Insert size
        $validSizes = array_merge(get_intermediate_image_sizes(), ['full']);
        $sanitized['insert_size'] = self::oneOf($input['insert_size'] ?? 'medium', $validSizes, 'medium');

        // Insert link
        $validLinks = ['file', 'attachment', 'none'];
        $sanitized['insert_link'] = self::oneOf($input['insert_link'] ?? 'file', $validLinks, 'file');

        // Custom HTML template
        $sanitized['custom_html'] = wp_kses_post($input['custom_html'] ?? '');

        // Display settings
        $sanitized['display_thumbnail_icon'] = !empty($input['display_thumbnail_icon']);
        $sanitized['hide_generated_images'] = !empty($input['hide_generated_images']);
        $sanitized['keep_on_uninstall'] = !empty($input['keep_on_uninstall']);

        return $sanitized;
    }

    /**
     * The value when it is one of the list, otherwise the default
     *
     * A key missing from the input used to be checked as its default and
     * then read from the input anyway, which saved null -- a partial save
     * from code or WP-CLI lost the format and the background (review after
     * 1.4.33).
     *
     * @param mixed              $value
     * @param array<int, string> $allowed
     */
    private static function oneOf($value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Enqueue admin assets
     *
     * Untyped: a plugin running admin_enqueue_scripts by hand, with no
     * argument, was a fatal error (review of 1.4.34).
     *
     * @param mixed $hook Current admin page
     */
    public function enqueueAssets($hook = ''): void
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

        // The script only on the settings page: on the Media Library it did
        // nothing, and every visit fetched it and a nonce for it (review of
        // 1.4.32). The stylesheet styles the Media Library's column too.
        if ($hook !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

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
                // What the scripts used to say in English whatever the admin language (R55-07).
                'scan' => __('Scan for PDFs', 'rapls-pdf-image-creator'),
                'stopped' => __('Stopped', 'rapls-pdf-image-creator'),
                // Said while the PDF being drawn finishes: the server has
                // already started on it (Codex review of 1.4.25, 6).
                'stopping' => __('Stopping after the current PDF...', 'rapls-pdf-image-creator'),
                'continueRun' => __('Continue', 'rapls-pdf-image-creator'),
                /* translators: %d: number of PDFs looked at so far */
                'scanning' => __('Scanning... %d PDFs so far', 'rapls-pdf-image-creator'),
                /* translators: %s: PDF file name */
                'processingFile' => __('Processing: %s', 'rapls-pdf-image-creator'),
                'failed' => __('Failed', 'rapls-pdf-image-creator'),
                'requestFailed' => __('Request failed', 'rapls-pdf-image-creator'),
                'errorShort' => __('Error', 'rapls-pdf-image-creator'),
                /* translators: %s: HTTP status text from the server */
                'httpStatus' => __('Status: %s', 'rapls-pdf-image-creator'),
                /* translators: %s: error text from the server */
                'httpError' => __('Error: %s', 'rapls-pdf-image-creator'),
                'statsFailed' => __('The statistics could not be read. Try again.', 'rapls-pdf-image-creator'),
                'rescan' => __('The choice changed: scan again before starting.', 'rapls-pdf-image-creator'),
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
     * @param array<int, string> $args
     * @return array<int, string>
     */
    public function removableQueryArgs($args): array
    {
        return array_merge((array) $args, ['rapls_pic_generated', 'rapls_pic_error', 'rapls_pic_rechecked', 'rapls_pic_dismiss_engine_notice', 'rapls_pic_dismiss_color_notice']);
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices(): void
    {
        $this->showColorUpdateNotice();

        $this->showRegenerateNotices();

        $this->showEngineNotice();
    }

    /**
     * What a regeneration says when it kept the previous thumbnail
     *
     * A post shows the old image, so it was kept, and the post still shows
     * the old picture until it is pointed at the new one (review after
     * 1.4.37).
     */
    public static function keptPreviousNote(): string
    {
        return __('The previous thumbnail is still shown in a post, so it was kept in the Media Library as an ordinary image. That post still shows the old picture; replace it there to show the new one.', 'rapls-pdf-image-creator');
    }

    /**
     * The notice after the Media Library's "Regenerate Thumbnail"
     */
    private function showRegenerateNotices(): void
    {
        // Success notice
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
        if (isset($_GET['rapls_pic_generated'])) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__('PDF thumbnail generated successfully.', 'rapls-pdf-image-creator');
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
            if ('kept' === $_GET['rapls_pic_generated']) {
                echo ' ' . esc_html(self::keptPreviousNote());
            }
            echo '</p>';
            echo '</div>';
        }

        // Error notice
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
        if (isset($_GET['rapls_pic_error'])) {
            // With the reason, and the way to the Status tab where the server
            // is described: "failed" alone left nothing to go on (review of
            // 1.4.32). The reason is this attempt's code, carried in the
            // address and said in this plugin's own words. The recorded
            // message was ImageMagick's text, with its command line and the
            // server's paths, shown to anyone who could edit the PDF -- and
            // the site's last failure, which may have been another PDF's
            // (review after 1.4.32). The Status tab, for administrators,
            // still shows the message itself.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no data processing
            // A string only: "?rapls_pic_error[]=x" made the cast an "Array to
            // string conversion" warning (review of 1.4.35).
            $code = is_string($_GET['rapls_pic_error']) ? sanitize_key(wp_unslash($_GET['rapls_pic_error'])) : '';
            $reason = self::failureReason($code);

            echo '<div class="notice notice-error is-dismissible">';
            echo '<p>' . esc_html__('Failed to generate PDF thumbnail.', 'rapls-pdf-image-creator');
            if ('' !== $reason) {
                echo ' ' . esc_html($reason);
            }
            if (current_user_can('manage_options')) {
                echo ' <a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '#tab-status')) . '">'
                    . esc_html__('See the Status tab', 'rapls-pdf-image-creator') . '</a>';
            }
            echo '</p>';
            echo '</div>';
        }
    }

    /**
     * Why a generation failed, in this plugin's words, from its code
     *
     * Never ImageMagick's own text, which carries its command line and the
     * server's paths. Empty for a code with nothing more to say than
     * "failed". Said by the regeneration notice and by Bulk Generate, which
     * said "Failed to generate thumbnail." for every reason (review after
     * 1.4.33).
     */
    public static function failureReason(string $code): string
    {
        $reason = FailureCode::describe($code)['label'];

        // ERROR too: from generate() it is the server check's "unable to
        // check" -- a test PDF ImageMagick could not read just now.
        if ('' === $reason && in_array($code, [FailureCode::NO_EXTENSION, FailureCode::PDF_BLOCKED_BY_POLICY, FailureCode::PDF_UNSUPPORTED, FailureCode::ERROR], true)) {
            $reason = __('This server cannot make PDF thumbnails at the moment.', 'rapls-pdf-image-creator');
        }

        return $reason;
    }

    /**
     * Warn when the server cannot render PDFs at all
     *
     * The plugin can be activated on a server with no ImageMagick, and used
     * to do so without a word: uploads produced no thumbnail and no message.
     * So this speaks up on every admin screen right after activation, or once
     * an upload has actually failed, and keeps speaking on the plugin's own
     * screens. It also says *which* of the two server problems this is,
     * because the fix differs.
     */
    private function showEngineNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $status = $this->generator->getAvailabilityStatus();

        if ('ok' === $status['code']) {
            // The host fixed it. Forget everything we recorded about it, so a
            // later failure starts from a clean slate. Each only when it is
            // there: this runs on every admin page, and deleting asks the
            // database whether or not there is anything to delete (review
            // after 1.4.33).
            if (false !== get_transient(RAPLS_PIC_ACTIVATION_NOTICE)) {
                delete_transient(RAPLS_PIC_ACTIVATION_NOTICE);
            }
            if (false !== get_option(Generator::UNAVAILABLE_OPTION, false)) {
                delete_option(Generator::UNAVAILABLE_OPTION);
            }
            return;
        }

        $screen = get_current_screen();
        $onOwnScreen = $screen
            && ('settings_page_' . self::PAGE_SLUG === $screen->id || 'upload' === $screen->id);

        $justActivated = (bool) get_transient(RAPLS_PIC_ACTIVATION_NOTICE);
        $uploadFailed = false !== get_option(Generator::UNAVAILABLE_OPTION, false);
        $dismissed = get_user_meta(get_current_user_id(), self::ENGINE_NOTICE_META, true) === $status['code'];

        if (!$onOwnScreen) {
            if ($dismissed || (!$justActivated && !$uploadFailed)) {
                return;
            }
        }

        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('Rapls PDF Image Creator:', 'rapls-pdf-image-creator') . ' ';
        echo esc_html($status['label']) . '</strong></p>';

        echo '<p>' . esc_html($status['summary']) . '</p>';

        if ($uploadFailed) {
            echo '<p>' . esc_html__('A PDF was uploaded and no thumbnail could be generated for this reason.', 'rapls-pdf-image-creator') . '</p>';
        }

        if ('' !== $status['action']) {
            echo '<p><strong>' . esc_html__('What to do:', 'rapls-pdf-image-creator') . '</strong> ';
            echo esc_html($status['action']) . '</p>';
        }

        if ('' !== $status['detail']) {
            echo '<p><code>' . esc_html($status['detail']) . '</code></p>';
        }

        echo '<p>';
        if (!$onOwnScreen) {
            echo '<a class="button" href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '#tab-status')) . '">';
            echo esc_html__('Plugin status', 'rapls-pdf-image-creator');
            echo '</a> ';
            echo '<a class="button" href="' . esc_url($this->getEngineNoticeDismissUrl($status['code'])) . '">';
            echo esc_html__('Dismiss', 'rapls-pdf-image-creator');
            echo '</a> ';
        }
        echo '<a href="' . esc_url(admin_url('site-health.php')) . '">';
        echo esc_html__('See Site Health for the full report', 'rapls-pdf-image-creator');
        echo '</a>';
        echo '</p>';

        echo '</div>';
    }

    /**
     * Handle dismissal of the "no engine" notice
     */
    public function maybeDismissEngineNotice(): void
    {
        if (!isset($_GET['rapls_pic_dismiss_engine_notice'])) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rapls_pic_dismiss_engine_notice')) {
            return;
        }

        // A string only: sanitize_key() took an array to a TypeError before
        // WordPress 6.1 (review of 1.4.36).
        $code = is_string($_GET['rapls_pic_dismiss_engine_notice']) ? sanitize_key(wp_unslash($_GET['rapls_pic_dismiss_engine_notice'])) : '';
        if ('' === $code) {
            return;
        }

        update_user_meta(get_current_user_id(), self::ENGINE_NOTICE_META, $code);
        delete_transient(RAPLS_PIC_ACTIVATION_NOTICE);
    }

    /**
     * Build the dismissal URL for the "no engine" notice
     *
     * @param string $code Status code being dismissed.
     */
    private function getEngineNoticeDismissUrl(string $code): string
    {
        // This screen's own address, query and all: built from the file name
        // alone, it went from another plugin's admin.php?page=x to a bare
        // admin.php, which shows nothing, and from Pages to Posts (review of
        // 1.4.32). The argument is taken off the address afterwards
        // (removableQueryArgs()). Without the screen's own nonce: this
        // link's took its place, and a confirmation screen -- Plugins'
        // "Delete" -- answered "The link you followed has expired" (review
        // after 1.4.32). Its action goes too only when it came with a nonce,
        // a one-time request: "action=edit" is the screen itself, and
        // post.php without it is the Posts list.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only whether one is there
        $oneTime = isset($_GET['_wpnonce']);
        $here = remove_query_arg($oneTime ? ['_wpnonce', '_wp_http_referer', 'action', 'action2'] : ['_wp_http_referer']);

        return wp_nonce_url(
            add_query_arg('rapls_pic_dismiss_engine_notice', $code, $here),
            'rapls_pic_dismiss_engine_notice'
        );
    }

    /**
     * Throw away the cached probe results and measure again
     *
     * The probes cache for twelve hours, some for a day or a week, which is
     * right for a value read on every admin page load and wrong for the
     * moment after a host says "we have enabled PDFs for you". Without this
     * the site owner waits half a day or more to find out whether that is
     * true.
     */
    public function maybeRecheckProbes(): void
    {
        if (!isset($_GET['rapls_pic_recheck'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'rapls_pic_recheck')) {
            return;
        }

        foreach (\Rapls\PDFImageCreator\Engine\ImagickEngine::probeTransients() as $transient) {
            delete_transient($transient);
        }

        // Where the ICC profiles are, too: "not found" is kept for an hour,
        // and a CMYK profile the host has just installed still read as
        // missing after "Check this server again" (review after 1.4.31).
        (new \Rapls\PDFImageCreator\Engine\ColorProfile())->flushCache();

        // Whatever the last failure was, it was measured against the old
        // answer. Let the next generation decide.
        delete_option(Generator::UNAVAILABLE_OPTION);

        wp_safe_redirect(add_query_arg(
            ['page' => 'rapls-pdf-image-creator', 'rapls_pic_rechecked' => '1'],
            admin_url('options-general.php')
        ) . '#tab-status');
        exit;
    }

    /**
     * URL for the re-check button
     */
    public function getRecheckUrl(): string
    {
        return wp_nonce_url(
            add_query_arg(
                ['page' => 'rapls-pdf-image-creator', 'rapls_pic_recheck' => '1'],
                admin_url('options-general.php')
            ),
            'rapls_pic_recheck'
        );
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $config = $this->settings->get();
        $capabilities = $this->generator->checkCapabilities();
        $last_failure = $this->generator->getLastFailure();
        $recheck_url = $this->getRecheckUrl();

        include RAPLS_PIC_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
}
