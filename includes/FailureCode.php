<?php
/**
 * Why a thumbnail did not appear
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

/**
 * The vocabulary for generation failures.
 *
 * Before 1.4.0 a failed generation produced a translated sentence and nothing
 * else. That is enough to show someone, and not enough to act on: "the server
 * has no PDF support" and "this particular file is 1.4 billion pixels" need
 * different answers, and a sentence in Japanese cannot be compared, counted or
 * branched on.
 *
 * Four of these codes are the same strings ImagickEngine::getAvailabilityStatus()
 * already used, on purpose. The distinction that matters most to someone stuck
 * -- ask the host to install Imagick, or ask the host to allow PDFs -- was
 * already made there, and making it twice under two names would be how the two
 * halves drift apart.
 */
final class FailureCode
{
    // Environment. Shared with getAvailabilityStatus().
    public const NO_EXTENSION = 'no_extension';
    public const PDF_BLOCKED_BY_POLICY = 'pdf_blocked_by_policy';
    public const PDF_UNSUPPORTED = 'pdf_unsupported';

    // This attachment.
    public const NOT_A_PDF = 'not_a_pdf';
    public const SOURCE_MISSING = 'source_missing';

    // This attempt.
    public const BLANK_RENDER = 'blank_render';
    public const RESOURCE_LIMIT = 'resource_limit';
    public const WRITE_FAILED = 'write_failed';
    public const RENDER_ERROR = 'render_error';
    public const ERROR = 'error';

    /**
     * Codes that will produce the same result if tried again with the same
     * settings.
     *
     * Nothing in the free plugin retries, so this is here for callers that do.
     * Retrying a permanent failure is how a queue stops making progress while
     * looking busy.
     *
     * @return array<int, string>
     */
    public static function permanent(): array
    {
        return [
            self::NO_EXTENSION,
            self::PDF_BLOCKED_BY_POLICY,
            self::PDF_UNSUPPORTED,
            self::NOT_A_PDF,
            self::SOURCE_MISSING,
            self::RESOURCE_LIMIT,
            self::BLANK_RENDER,
        ];
    }

    public static function isPermanent(string $code): bool
    {
        return in_array($code, self::permanent(), true);
    }

    /**
     * Read a code out of an ImageMagick exception message.
     *
     * ImageMagick reports these as text and nothing else, so matching the text
     * is the only option. The patterns are deliberately loose: exact wording
     * moves between versions, and a message that no longer matches must fall
     * back to "something went wrong" rather than to a confident wrong answer.
     */
    public static function fromExceptionMessage(string $message): string
    {
        // "NotAuthorized" arrives with no space in it. An earlier version of
        // this pattern looked for "not authoriz" and missed every single one.
        if (preg_match('/not\s*authoriz|security policy/i', $message)) {
            return self::PDF_BLOCKED_BY_POLICY;
        }

        if (preg_match('/no decode delegate|FailedToExecuteCommand|delegate/i', $message)) {
            return self::PDF_UNSUPPORTED;
        }

        // The pixel cache refusing to open. Permanent for this file at this
        // resolution -- the same request will exhaust the same limit again --
        // and it says nothing at all about the next file.
        if (preg_match('/cache\s*resources\s*exhausted|OpenPixelCache|width or height exceeds limit/i', $message)) {
            return self::RESOURCE_LIMIT;
        }

        if (preg_match('/unable to (open|write)|permission denied|no space left/i', $message)) {
            return self::WRITE_FAILED;
        }

        return self::RENDER_ERROR;
    }

    /**
     * A sentence for a person, and what to do about it.
     *
     * Environment codes are not answered here. ImagickEngine::statusFor()
     * already writes those, with the policy file path filled in where it could
     * be found, and this returns an empty action for them so the caller reaches
     * for that instead.
     *
     * @return array{label: string, action: string}
     */
    public static function describe(string $code): array
    {
        switch ($code) {
            case self::NOT_A_PDF:
                return [
                    'label' => __('Not a PDF file.', 'rapls-pdf-image-creator'),
                    'action' => '',
                ];

            case self::SOURCE_MISSING:
                return [
                    'label' => __('The PDF file is missing from the uploads folder.', 'rapls-pdf-image-creator'),
                    'action' => __('The attachment exists in the Media Library but its file does not. Re-upload the PDF.', 'rapls-pdf-image-creator'),
                ];

            case self::BLANK_RENDER:
                return [
                    'label' => __('The page rendered as a blank white image.', 'rapls-pdf-image-creator'),
                    'action' => __('Nothing was drawn. ImageMagick raised no error, so this is almost always the PDF renderer underneath it — Ghostscript — being too old for the file. Ghostscript 9.27 fails on PDFs that Ghostscript 10 handles, reporting "Error reading a content stream" and writing an empty page. Ask your hosting provider which version is installed and whether it can be updated.', 'rapls-pdf-image-creator'),
                ];

            case self::RESOURCE_LIMIT:
                return [
                    'label' => __('The page was too large for ImageMagick to open.', 'rapls-pdf-image-creator'),
                    'action' => __('Lower the resolution on the Settings tab and generate again. Large-format pages at a high DPI can run to hundreds of millions of pixels.', 'rapls-pdf-image-creator'),
                ];

            case self::WRITE_FAILED:
                return [
                    'label' => __('The image could not be written to the uploads folder.', 'rapls-pdf-image-creator'),
                    'action' => __('Check the permissions on wp-content/uploads, and that the disk is not full.', 'rapls-pdf-image-creator'),
                ];

            case self::RENDER_ERROR:
                return [
                    'label' => __('ImageMagick could not render the page.', 'rapls-pdf-image-creator'),
                    'action' => __('The exact message is below. If it mentions gs, the PDF delegate failed on this particular file rather than on the server as a whole.', 'rapls-pdf-image-creator'),
                ];

            default:
                // Environment codes and anything unrecognised.
                return ['label' => '', 'action' => ''];
        }
    }

    /**
     * Every code this class knows about
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::NO_EXTENSION,
            self::PDF_BLOCKED_BY_POLICY,
            self::PDF_UNSUPPORTED,
            self::NOT_A_PDF,
            self::SOURCE_MISSING,
            self::RESOURCE_LIMIT,
            self::BLANK_RENDER,
            self::WRITE_FAILED,
            self::RENDER_ERROR,
            self::ERROR,
        ];
    }
}
