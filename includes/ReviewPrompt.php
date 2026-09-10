<?php
/**
 * One-time review request
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * Asks for a WordPress.org review once, and then never again.
 *
 * Ported from Rapls Passkey 0.13.69, where the conditions were worked out and
 * have not needed changing since. They are deliberately narrow:
 *
 * - **A week after activation.** An opinion formed in the first hour is not
 *   worth asking for.
 * - **Only if a thumbnail was actually produced.** Someone who never got the
 *   plugin working has nothing to review and every reason to resent being
 *   asked. This plugin has more ways to fail than most -- a missing PDF
 *   delegate, a policy that forbids it, a Ghostscript too old for the file --
 *   and each of them is a person who should never see this notice.
 * - **Only on this plugin's own screens.** A review request on somebody else's
 *   settings page is what Guideline 11 exists to stop, and it is not worth the
 *   goodwill it costs.
 * - **Once.** Whichever button is used, including the notice's own close
 *   button, the answer is recorded site-wide and it does not come back.
 */
final class ReviewPrompt
{
    /** Site option holding the outcome: unset, or settled. */
    public const OPTION = 'rapls_pic_review_prompt';

    /** When the plugin was activated. GMT, 'Y-m-d H:i:s'. */
    public const ACTIVATED_OPTION = 'rapls_pic_activated_at';

    /** Days of use before asking. */
    public const AFTER_DAYS = 7;

    /** Query arg used to settle the prompt. */
    private const ARG = 'rapls_pic_review';

    /** admin-ajax action behind the notice's close button. */
    private const AJAX = 'rapls_pic_review_dismiss';

    /** Where the review is left. */
    private const URL = 'https://wordpress.org/support/plugin/rapls-pdf-image-creator/reviews/#new-post';

    public function init(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_init', [$this, 'handleDismiss']);
        add_action('wp_ajax_' . self::AJAX, [$this, 'handleAjaxDismiss']);
    }

    /**
     * Record a dismissal made with the notice's own close button
     *
     * WordPress's `is-dismissible` only hides the notice on the page it was
     * clicked; nothing is stored, so the next page load brings it back. That
     * turns "asked once" into a nag, which is the one thing this class exists
     * not to be.
     */
    public function handleAjaxDismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::AJAX);
        update_option(self::OPTION, 'done', false);
        wp_send_json_success();
    }

    /**
     * Record the answer, then go wherever that answer points
     *
     * Every button lands here first, including "Write a review" -- otherwise
     * the one person who did what was asked would be asked again on the next
     * page load, which is the worst of the possible outcomes.
     */
    public function handleDismiss(): void
    {
        if (!isset($_GET[self::ARG])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer(self::ARG);

        $answer = sanitize_key(wp_unslash($_GET[self::ARG]));

        update_option(self::OPTION, 'done', false);

        if ('go' === $answer) {
            // Not wp_safe_redirect: the destination is this class's own
            // constant, never anything that arrived with the request.
            wp_redirect(self::URL);
            exit;
        }

        wp_safe_redirect(remove_query_arg([self::ARG, '_wpnonce']));
        exit;
    }

    /**
     * A nonced link back to the handler
     *
     * @param string $answer 'go' to continue to WordPress.org, anything else to stay.
     */
    private function settleUrl(string $answer): string
    {
        return wp_nonce_url(add_query_arg(self::ARG, $answer), self::ARG);
    }

    /**
     * Print the request, if this is the one moment to print it
     */
    public function render(): void
    {
        if (!$this->shouldAsk()) {
            return;
        }

        // Bound to this notice only, and to the button WordPress itself adds
        // for is-dismissible. keepalive lets the request outlive the page when
        // closing the notice is the last thing done before navigating away.
        $js = sprintf(
            '(function(){var n=document.getElementById("rapls-pic-review");if(!n)return;'
            . 'n.addEventListener("click",function(e){'
            . 'if(!e.target.classList||!e.target.classList.contains("notice-dismiss"))return;'
            . 'var b=new FormData();b.append("action",%s);b.append("_ajax_nonce",%s);'
            . 'fetch(%s,{method:"POST",body:b,credentials:"same-origin",keepalive:true});});})();',
            wp_json_encode(self::AJAX),
            wp_json_encode(wp_create_nonce(self::AJAX)),
            wp_json_encode(admin_url('admin-ajax.php'))
        );
        wp_add_inline_script('common', $js);

        ?>
        <div class="notice notice-info is-dismissible" id="rapls-pic-review">
            <p>
                <strong><?php esc_html_e('Rapls PDF Image Creator', 'rapls-pdf-image-creator'); ?></strong>
                &mdash;
                <?php esc_html_e('Your PDFs have been getting thumbnails for a week now. If it has been working out, would you leave a review? It is the main way other people find the plugin.', 'rapls-pdf-image-creator'); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($this->settleUrl('go')); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Write a review', 'rapls-pdf-image-creator'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($this->settleUrl('did')); ?>">
                    <?php esc_html_e('Already did', 'rapls-pdf-image-creator'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($this->settleUrl('no')); ?>">
                    <?php esc_html_e('No thanks', 'rapls-pdf-image-creator'); ?>
                </a>
            </p>
            <p class="description">
                <?php esc_html_e('Asked once. Whichever you choose — including closing this notice — it will not come back.', 'rapls-pdf-image-creator'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Every condition that has to hold before asking
     */
    private function shouldAsk(): bool
    {
        /**
         * Filter whether the review request may be shown at all.
         *
         * Return false to switch it off for good -- for a site owner who does
         * not want it, or for a build that ships without it.
         *
         * @since 1.4.2
         *
         * @param bool $enabled True by default.
         */
        if (!(bool) apply_filters('rapls_pdf_image_creator_show_review_prompt', true)) {
            return false;
        }

        if ('' !== (string) get_option(self::OPTION, '')) {
            return false;
        }

        // Only someone who could act on it, and only on this plugin's screens.
        if (!current_user_can('manage_options')) {
            return false;
        }

        if (!$this->onOwnScreen()) {
            return false;
        }

        if (!$this->usedForAWeek()) {
            return false;
        }

        return $this->hasGeneratedSomething();
    }

    /**
     * Is the current screen one of this plugin's own?
     */
    private function onOwnScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        if (null === $screen) {
            return false;
        }

        return 'settings_page_' . Admin::PAGE_SLUG === $screen->id;
    }

    /**
     * Has the plugin been installed for the waiting period?
     */
    private function usedForAWeek(): bool
    {
        $since = (string) get_option(self::ACTIVATED_OPTION, '');

        if ('' === $since) {
            return false;
        }

        $timestamp = strtotime($since . ' UTC');

        if (false === $timestamp) {
            return false;
        }

        return (time() - $timestamp) >= (self::AFTER_DAYS * DAY_IN_SECONDS);
    }

    /**
     * Did this site ever get a thumbnail out of the plugin?
     *
     * The question every other condition is in aid of. On a server without a
     * PDF delegate the answer is no and stays no, and the notice never
     * appears -- which is the point. Asking someone who has been staring at a
     * PDF icon for a week to go and review the plugin would be worse than
     * asking nobody at all.
     *
     * One indexed EXISTS against postmeta, reached only after every cheaper
     * condition has already passed, and at most a handful of times in a
     * site's life -- once settled, the option short-circuits everything above.
     */
    private function hasGeneratedSomething(): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return false;
        }

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
                '_rapls_pic_is_thumbnail'
            )
        );

        return null !== $found;
    }
}
