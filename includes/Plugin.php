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
     * Option holding the version the database was last migrated to
     */
    public const VERSION_OPTION = 'rapls_pic_version';

    /**
     * Option set when users should be told to regenerate thumbnails
     */
    public const COLOR_NOTICE_OPTION = 'rapls_pic_color_notice';

    /**
     * First version whose colour conversion differs from earlier releases
     */
    private const COLOR_FIX_VERSION = '1.1.0';

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
     * Site Health integration
     */
    private ?SiteHealth $siteHealth = null;
    private ?ReviewPrompt $reviewPrompt = null;

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
        $this->bulkProcessor = new BulkProcessor($this->generator);
    }

    /**
     * Initialize the plugin
     */
    public function init(): void
    {
        $this->maybeUpgrade();

        // Initialize components
        $this->mediaLibrary->init();
        $this->inserter->init();

        // Register AJAX handlers early (needed for admin-ajax.php requests)
        $this->bulkProcessor->init();

        // Admin screens, and admin-ajax.php too: is_admin() is true there,
        // and ReviewPrompt's dismissal is an AJAX request.
        if (is_admin()) {
            $this->admin = new Admin($this->settings, $this->generator);
            $this->admin->init();

            $this->siteHealth = new SiteHealth($this->settings, $this->generator);
            $this->siteHealth->init();

            $this->reviewPrompt = new ReviewPrompt($this->generator);
            $this->reviewPrompt->init();
        }

        // Register shortcodes
        $this->registerShortcodes();
    }

    /**
     * Run one-time work after an update
     *
     * Nothing here touches the media library. The colour fix changes how new
     * thumbnails are rendered, so existing ones stay as they are until the
     * user regenerates them; all this does is say so once.
     */
    private function maybeUpgrade(): void
    {
        $stored = get_option(self::VERSION_OPTION, '');

        if (RAPLS_PIC_VERSION === $stored) {
            return;
        }

        // No version recorded but settings present means an upgrade from a
        // release older than this option. A genuinely fresh install has
        // neither, because activation writes both.
        $previous = '' === $stored && get_option(Settings::OPTION_NAME) ? '1.0.0' : $stored;

        // The version first, and the rest only once it is recorded: where
        // the write fails, everything below ran again on every request,
        // and the probes were measured afresh each time (review after
        // 1.4.37). It is tried again on the next request instead.
        // Autoloaded: this is read on every request, and not autoloaded it
        // cost a query on each (review after 1.4.33). update_option() takes
        // the new autoload with the new value, so an existing install changes
        // over at this, its next update.
        update_option(self::VERSION_OPTION, RAPLS_PIC_VERSION, true);
        if (RAPLS_PIC_VERSION !== get_option(self::VERSION_OPTION, '')) {
            return;
        }

        if ('' !== $previous && version_compare($previous, self::COLOR_FIX_VERSION, '<')) {
            update_option(self::COLOR_NOTICE_OPTION, '1', false);
        }

        // Host profiles may have changed with the platform; do not trust the
        // paths cached by the previous version.
        (new \Rapls\PDFImageCreator\Engine\ColorProfile())->flushCache();

        // Nor what the previous version measured about the server: how it
        // reads PDFs, whether a page can be asked for, whether Ghostscript
        // needed the workaround. The measuring may have changed with the
        // version, and the old answers stood for up to a week (review after
        // 1.4.31). Measured again when next needed.
        foreach (\Rapls\PDFImageCreator\Engine\ImagickEngine::probeTransients() as $transient) {
            delete_transient($transient);
        }

        // Sites that were installed before the review request existed have no
        // activation date. Start their clock now rather than never: a week
        // from this upgrade is a fair moment to ask, and back-dating to an
        // activation nobody recorded would ask them today.
        if (!get_option(ReviewPrompt::ACTIVATED_OPTION)) {
            add_option(ReviewPrompt::ACTIVATED_OPTION, gmdate('Y-m-d H:i:s'), '', false);
        }
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
     * The shortcode's id, when it names a PDF; 0 otherwise
     *
     * Any post ID was taken: the thumbnail lookup falls back to a post's
     * featured image and the alt text is its title, so a Contributor could
     * preview [rapls_pdf_thumbnail id=N] in a draft of their own and read the
     * featured image and title of someone else's private post or draft
     * (review of 1.4.32). These shortcodes are for PDFs.
     *
     * And only a PDF this visitor could open: one attached to a private
     * post, a draft or a post behind a password gave its thumbnail, title
     * and address to anyone reading the post the shortcode was put in
     * (review of 1.4.33). get_post_status() answers for an attachment with
     * its parent's status, as core's own attachment pages do.
     *
     * @param mixed $id
     */
    public static function pdfId($id): int
    {
        $id = absint($id);

        if (!$id) {
            return 0;
        }

        if ('application/pdf' !== get_post_mime_type($id)) {
            /**
             * Whether the shortcodes take any post's ID, as in 1.4.20
             *
             * Off by default: only a PDF's. On, [rapls_pdf_thumbnail id=N]
             * and the others show what they showed up to 1.4.20 for any
             * post -- its featured image, its title -- to a reader who may
             * see that post: published, or one they may read, and behind a
             * password only to someone who may edit it. Pages made with a
             * post's ID came out empty after 1.4.32 (review after 1.4.37).
             *
             * @since 1.4.43
             *
             * @param bool $any Whether to.
             * @param int  $id  The ID the shortcode was given.
             */
            if (true !== apply_filters('rapls_pdf_image_creator_shortcode_any_post', false, $id)) {
                return 0;
            }

            return self::viewablePost($id) ? $id : 0;
        }

        return self::viewable($id) ? $id : 0;
    }

    /**
     * Whether whoever is reading may see this post, for a shortcode given its ID
     *
     * An attachment as viewable() asks; any other post by its own password
     * and status.
     */
    private static function viewablePost(int $id): bool
    {
        $post = get_post($id);

        if (!is_object($post)) {
            return false;
        }

        if ('attachment' === ($post->post_type ?? '')) {
            return self::viewable($id);
        }

        if (post_password_required($id)) {
            return current_user_can('edit_post', $id);
        }

        // A post of a type with no public pages -- a private custom type, a
        // reusable block -- is shown by nothing to a visitor; published, it
        // was shown here to anyone (review of 1.4.39). Only someone who may
        // edit it sees it: read_post of a published post is plain "read".
        // Asked here only, for a post that is not an attachment: viewable()
        // must not ask it of a PDF's parent (it hid PDFs in reusable blocks,
        // review after 1.4.34).
        if (function_exists('is_post_type_viewable') && !is_post_type_viewable((string) ($post->post_type ?? ''))) {
            return current_user_can('edit_post', $id);
        }

        return self::publicOrReadable($id);
    }

    /**
     * Whether whoever is reading may see this attachment
     *
     * Behind a password, only someone who may edit it. read_post was asked
     * there, and core maps it to plain "read" for a published post without
     * looking at the password, so any logged-in Subscriber was shown what
     * the password hid (review of 1.4.34). Core's own content asks for the
     * password of everyone; editing it is where this stops short of that.
     *
     * Not asked whether the parent's type has public pages: a PDF attached
     * to a reusable block, or to a post that no longer exists, was shown
     * before and went missing for every visitor (review after 1.4.34).
     */
    private static function viewable(int $id): bool
    {
        $parent = (int) wp_get_post_parent_id($id);

        if ($parent && post_password_required($parent)) {
            return current_user_can('edit_post', $id);
        }

        return self::publicOrReadable($id);
    }

    /**
     * Published -- for an attachment, its parent's status, as core answers
     * it -- or one this reader may read
     */
    private static function publicOrReadable(int $id): bool
    {
        $name = (string) get_post_status($id);
        // What core answers for an attachment: its parent's status; with no
        // parent, "publish"; a trashed parent's status before the trash
        // ("publish" when none was kept); with a parent that no longer
        // exists, false. False was asked as read_post of a status nothing
        // registers, and the PDF went missing for visitors (review of
        // 1.4.37's fixes). But '' or false is also what a filter on
        // get_post_status may answer for a parent that is there: the parent
        // itself is looked at then, and only one that is gone makes the
        // attachment stand on its own, as it was shown before. "inherit"
        // left unresolved is the same question in an older answer.
        // Whose read_post decides: the attachment's, unless its status had
        // to be found on the parent -- asked of an attachment whose status
        // is '' or none, map_meta_cap() calls _doing_it_wrong() and answers
        // for edit_others_posts (review of 1.4.37's fixes).
        $askAbout = $id;
        if ('' === $name || 'inherit' === $name) {
            $parent = (int) wp_get_post_parent_id($id);
            $post = $parent ? get_post($parent) : null;
            if (!$parent || !is_object($post)) {
                return true;
            }
            // The parent's own status as stored: asked through
            // get_post_status() again, the same filter answered again.
            $name = (string) ($post->post_status ?? '');
            $askAbout = $parent;
        }
        $status = get_post_status_object($name);
        if ($status && !empty($status->public)) {
            return true;
        }

        return current_user_can('read_post', $askAbout);
    }

    /**
     * The attributes a shortcode hands the image: its class, when it has one
     *
     * An empty class replaced the default "pic-thumbnail" and core's own
     * classes alike, and the image came out as <img class=""> (review of
     * 1.4.33).
     *
     * @return array<string, string>
     */
    private static function imageAttributes(string $classes): array
    {
        $classes = self::classList($classes);

        return '' === $classes ? [] : ['class' => $classes];
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

            $id = self::pdfId($atts['id']);
            if (!$id) {
                return '';
            }

            return $this->generator->getThumbnailImage($id, (string) $atts['size'], self::imageAttributes((string) $atts['class']));
        });

        // [rapls_pdf_thumbnail_url id="123" size="medium"]
        add_shortcode('rapls_pdf_thumbnail_url', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'size' => 'thumbnail',
            ], $atts, 'rapls_pdf_thumbnail_url');

            $id = self::pdfId($atts['id']);
            if (!$id) {
                return '';
            }

            return esc_url($this->generator->getThumbnailUrl($id, (string) $atts['size']) ?? '');
        });

        // [rapls_pdf_clickable_thumbnail id="123" size="medium"]
        add_shortcode('rapls_pdf_clickable_thumbnail', function ($atts): string {
            $atts = shortcode_atts([
                'id' => 0,
                'size' => 'thumbnail',
                'class' => '',
                'target' => '_blank',
            ], $atts, 'rapls_pdf_clickable_thumbnail');

            $id = self::pdfId($atts['id']);
            if (!$id) {
                return '';
            }

            $pdfUrl = wp_get_attachment_url($id);
            if (!$pdfUrl) {
                return '';
            }

            $image = $this->generator->getThumbnailImage($id, (string) $atts['size'], self::imageAttributes((string) $atts['class']));

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

            $id = self::pdfId($atts['id']);
            if (!$id) {
                return '';
            }

            $pdfUrl = wp_get_attachment_url($id);
            if (!$pdfUrl) {
                return '';
            }

            $content = '';
            if ($atts['show_thumbnail'] === 'true') {
                $content .= $this->generator->getThumbnailImage($id, (string) $atts['size']);
            }

            $text = $atts['text'] ?: get_the_title($id);
            $content .= '<span class="rapls-pic-download-text">' . esc_html($text) . '</span>';

            return sprintf(
                '<a href="%s" class="%s" download>%s</a>',
                esc_url($pdfUrl),
                esc_attr(self::classList((string) $atts['class'])),
                $content
            );
        });
    }

    /**
     * A shortcode's class attribute, one class at a time
     *
     * sanitize_html_class() takes one class: given the whole attribute it
     * dropped the spaces, and class="alignleft shadow" became
     * "alignleftshadow" (Codex review of 1.4.25, 7). Each class is cleaned on
     * its own and the ones left are joined with a space -- the same for all
     * three shortcodes that take one, the download link included.
     */
    public static function classList(string $classes): string
    {
        $clean = [];

        foreach ((array) preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) as $class) {
            $class = sanitize_html_class((string) $class);

            if ('' !== $class) {
                $clean[] = $class;
            }
        }

        return implode(' ', array_unique($clean));
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
