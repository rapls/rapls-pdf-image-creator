<?php
/**
 * Telling people the add-on exists
 *
 * Everything this plugin says about the paid add-on lives in this file: the
 * sales page's address, the words, and when each of the five places shows
 * (CLAUDE.md, "Telling people the add-on exists"). Nothing here looks for
 * the add-on: it answers the rapls_pdf_image_creator_show_upsell filter,
 * and a site owner can too. Nothing a control on these screens does
 * depends on it.
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * The five places: A the Plugins screen row, B a card at the bottom of the
 * Settings tab, C the third of three options on the Status tab where this
 * server cannot render PDFs, D one line under the page-number field after
 * the page was changed from the first, until dismissed, E one line on the
 * Status tab where this server renders PDFs.
 */
final class Upsell
{
    /** The add-on's sales page. Links carry UTM parameters; nothing is sent from the site. */
    private const URL = 'https://raplsworks.com/rapls-pdf-image-creator-pro/';

    /** The query argument and nonce action that dismiss D. */
    private const DISMISS = 'rapls_pic_dismiss_page_hint';

    /** User meta that D was dismissed, for good. */
    public const DISMISSED_META = 'rapls_pic_page_hint_dismissed';

    public function init(): void
    {
        add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 2);
        add_action('admin_init', [$this, 'handleDismiss']);
    }

    /**
     * Whether to say anything at all
     *
     * True unless something returns false: the add-on does, so its own
     * users are not sold what they have, and a site owner may.
     */
    public static function shows(): bool
    {
        return false !== apply_filters('rapls_pdf_image_creator_show_upsell', true);
    }

    /**
     * The sales page, with where the link was
     *
     * @param string $campaign a to e: which of the five places.
     */
    public static function url(string $campaign): string
    {
        return add_query_arg(
            [
                'utm_source' => 'pic-free',
                'utm_medium' => 'plugin',
                'utm_campaign' => $campaign,
            ],
            self::URL
        );
    }

    /**
     * A: a link in this plugin's row on the Plugins screen
     *
     * @param mixed $links Core passes the row's links; another filter may pass anything.
     * @param mixed $file  The plugin's basename.
     * @return mixed
     */
    public function pluginRowMeta($links, $file = '')
    {
        if (!is_array($links) || RAPLS_PIC_PLUGIN_BASENAME !== $file || !self::shows()) {
            return $links;
        }

        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url(self::url('a')),
            esc_html__('Optional add-on', 'rapls-pdf-image-creator')
        );

        return $links;
    }

    /**
     * B: a small card at the bottom of the Settings tab
     *
     * What the add-on does, by its results; what it needs, as plainly; and
     * that nothing on this page depends on it.
     */
    public static function renderSettingsCard(): void
    {
        if (!self::shows()) {
            return;
        }
        ?>
        <div class="rapls-pic-upsell-card">
            <p class="rapls-pic-upsell-lead">
                <?php esc_html_e('Everything on this page stays free.', 'rapls-pdf-image-creator'); ?>
            </p>
            <h3><?php esc_html_e('Rapls PDF Image Creator Pro, an optional add-on', 'rapls-pdf-image-creator'); ?></h3>
            <ul>
                <li><?php esc_html_e('Crops white margins, keeps the top of the page, or pads to a fixed shape, so small thumbnails stay readable.', 'rapls-pdf-image-creator'); ?></li>
                <li><?php esc_html_e('Chooses the page and the crop for each PDF on its own edit screen.', 'rapls-pdf-image-creator'); ?></li>
                <li><?php esc_html_e('Makes thumbnails in a background queue, retries what may be temporary, and says why one could not be made.', 'rapls-pdf-image-creator'); ?></li>
                <li><?php esc_html_e('Replaces a PDF in place, keeping the previous file so the replacement can be undone.', 'rapls-pdf-image-creator'); ?></li>
                <li><?php esc_html_e('Renders with Ghostscript directly where ImageMagick cannot read PDFs. This needs a host that lets PHP start programs (exec or proc_open) and has Ghostscript installed.', 'rapls-pdf-image-creator'); ?></li>
            </ul>
            <p>
                <?php esc_html_e('One-time purchase, no subscription. 14-day money-back guarantee.', 'rapls-pdf-image-creator'); ?>
                <a href="<?php echo esc_url(self::url('b')); ?>" target="_blank" rel="noopener"><?php esc_html_e('About the add-on', 'rapls-pdf-image-creator'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * C: on the Status tab, where this server cannot render PDFs, the options side by side
     *
     * Shown with the free ones, never after them. Says why this plugin
     * cannot start Ghostscript itself, what the add-on needs, and -- where
     * this server's disable_functions forbids both ways of starting a
     * program -- that the add-on cannot render here either. Reading the
     * setting starts nothing.
     */
    public static function renderStatusOptions(): void
    {
        if (!self::shows()) {
            return;
        }

        $blocked = self::programsBlocked();
        ?>
        <div class="rapls-pic-upsell-options">
            <h3><?php esc_html_e('Your options', 'rapls-pdf-image-creator'); ?></h3>
            <ol>
                <li>
                    <strong><?php esc_html_e('Ask your hosting provider (free).', 'rapls-pdf-image-creator'); ?></strong>
                    <?php esc_html_e('Send them the message above under "What to do". Most hosts can allow ImageMagick to read PDFs.', 'rapls-pdf-image-creator'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Use another host (free).', 'rapls-pdf-image-creator'); ?></strong>
                    <?php esc_html_e('A host whose ImageMagick can read PDFs makes thumbnails with this plugin as it is.', 'rapls-pdf-image-creator'); ?>
                </li>
                <li>
                    <strong><?php esc_html_e('Use the optional add-on (paid).', 'rapls-pdf-image-creator'); ?></strong>
                    <?php esc_html_e('This plugin cannot start Ghostscript itself: WordPress.org does not allow plugins in its directory to start other programs. The add-on is not in the directory and renders with Ghostscript directly. It needs a host that lets PHP start programs (exec or proc_open) and has Ghostscript installed.', 'rapls-pdf-image-creator'); ?>
                    <?php if ($blocked) : ?>
                        <strong><?php esc_html_e('This server does not let PHP start programs, so the add-on cannot render PDFs here either.', 'rapls-pdf-image-creator'); ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url(self::url('c')); ?>" target="_blank" rel="noopener"><?php esc_html_e('About the add-on', 'rapls-pdf-image-creator'); ?></a>
                    <?php endif; ?>
                </li>
            </ol>
        </div>
        <?php
    }

    /**
     * E: on the Status tab, where this server renders PDFs, one short line
     *
     * Added at the user's request (2026-10-15): a fifth place, light. Shown
     * only where rendering works, so it never meets C, and says what the
     * add-on adds to a working site, not that anything here is missing.
     */
    public static function renderStatusNote(): void
    {
        if (!self::shows()) {
            return;
        }
        ?>
        <p class="description rapls-pic-upsell-note">
            <?php esc_html_e('This server renders PDFs, and everything here works as it is. An optional add-on adds cropping, a page and a crop for each PDF, a background queue, and replacing a PDF in place.', 'rapls-pdf-image-creator'); ?>
            <a href="<?php echo esc_url(self::url('e')); ?>" target="_blank" rel="noopener"><?php esc_html_e('About the add-on', 'rapls-pdf-image-creator'); ?></a>
        </p>
        <?php
    }

    /**
     * Whether disable_functions forbids both exec and proc_open
     *
     * Only read, never tried: trying would start a process.
     */
    public static function programsBlocked(): bool
    {
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return in_array('exec', $disabled, true) && in_array('proc_open', $disabled, true);
    }

    /**
     * D: one line under the page-number field, once the page was changed from the first
     *
     * @param mixed $page The saved page setting (counted from 0).
     */
    public static function renderPageHint($page): void
    {
        if (!self::shows() || (int) $page <= 0 || !current_user_can('manage_options')) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::DISMISSED_META, true)) {
            return;
        }

        $dismiss = wp_nonce_url(add_query_arg(self::DISMISS, '1'), self::DISMISS);
        ?>
        <p class="description rapls-pic-upsell-hint">
            <?php esc_html_e('This page is used for every PDF. To choose a page for each PDF on its own, there is an optional add-on.', 'rapls-pdf-image-creator'); ?>
            <a href="<?php echo esc_url(self::url('d')); ?>" target="_blank" rel="noopener"><?php esc_html_e('About the add-on', 'rapls-pdf-image-creator'); ?></a>
            &middot;
            <a href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e('Do not show this again', 'rapls-pdf-image-creator'); ?></a>
        </p>
        <?php
    }

    /**
     * Dismiss D for this user, for good
     */
    public function handleDismiss(): void
    {
        if (!isset($_GET[self::DISMISS])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer(self::DISMISS);

        update_user_meta(get_current_user_id(), self::DISMISSED_META, 1);

        wp_safe_redirect(remove_query_arg([self::DISMISS, '_wpnonce']));
        exit;
    }
}
