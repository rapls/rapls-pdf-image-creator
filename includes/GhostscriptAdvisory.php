<?php
/**
 * A note about a Ghostscript vulnerability, CVE-2026-39919
 *
 * Not a fault in this plugin: ImageMagick hands PDFs to the server's
 * Ghostscript, as WordPress's own PDF preview does, and a Ghostscript before
 * 10.08.0 without the fix can be made to overflow a buffer by a crafted PDF.
 * Nothing here can tell whether the server's Ghostscript is fixed --
 * distributions backport the fix under the old version number -- so this
 * only says what to ask the host, and only where this server renders PDFs.
 * Everything about it is in this file, so it can be taken out in one place.
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

final class GhostscriptAdvisory
{
    /** The vulnerability this note is about. */
    public const CVE = 'CVE-2026-39919';

    /** A public record of it. A link only: nothing is fetched. */
    private const URL = 'https://osv.dev/vulnerability/CVE-2026-39919';

    /**
     * What to ask the host, in a sentence
     */
    public static function message(): string
    {
        return sprintf(
            /* translators: %s: the CVE identifier, CVE-2026-39919. */
            __('Ask your hosting provider whether the server\'s Ghostscript has the fix for %s: Ghostscript 10.08.0 or later, or a package from your distribution that includes the fix. The version number alone cannot tell, because distributions add the fix to older versions, so this plugin does not check it.', 'rapls-pdf-image-creator'),
            self::CVE
        );
    }

    /**
     * The note on the Status tab
     */
    public static function renderStatus(): void
    {
        ?>
        <div class="notice notice-info inline">
            <p>
                <strong>
                    <?php
                    printf(
                        /* translators: %s: the CVE identifier, CVE-2026-39919. */
                        esc_html__('Ghostscript vulnerability %s', 'rapls-pdf-image-creator'),
                        esc_html(self::CVE)
                    );
                    ?>
                </strong>
            </p>
            <p>
                <?php esc_html_e('This is not a fault in this plugin. ImageMagick uses the server\'s Ghostscript to draw PDFs, and so does WordPress\'s own PDF preview.', 'rapls-pdf-image-creator'); ?>
                <?php echo esc_html(self::message()); ?>
                <a href="<?php echo esc_url(self::URL); ?>" target="_blank" rel="noopener"><?php esc_html_e('Details', 'rapls-pdf-image-creator'); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * A field for the plugin's section of Site Health's Info tab
     *
     * @return array{label: string, value: string, debug: string}
     */
    public static function siteHealthField(): array
    {
        return [
            /* translators: %s: the CVE identifier, CVE-2026-39919. */
            'label' => sprintf(__('Ghostscript vulnerability %s', 'rapls-pdf-image-creator'), self::CVE),
            'value' => self::message(),
            'debug' => 'ask-host',
        ];
    }
}
