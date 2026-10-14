<?php
/**
 * Thumbnail Generator
 *
 * @package PDFImageCreator
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator;

use Rapls\PDFImageCreator\Engine\EngineInterface;
use Rapls\PDFImageCreator\Engine\ImagickEngine;
use Rapls\PDFImageCreator\Engine\ConversionResult;
use Rapls\PDFImageCreator\FailureCode;

/**
 * Generates thumbnail images from PDF files
 */
final class Generator
{
    /**
     * Meta key for storing thumbnail ID
     */
    public const THUMBNAIL_META_KEY = '_rapls_pic_thumbnail_id';

    /**
     * Settings manager
     */
    private Settings $settings;

    /**
     * Available engines
     *
     * @var EngineInterface[]
     */
    private array $engines = [];

    /**
     * What each generate() in this request came to, by PDF (outcomeFor())
     *
     * @var array<int, array{ok: bool, thumbnail_id: int|null, code: string, message: string}>
     */
    private array $outcomes = [];

    /**
     * Constructor
     *
     * @param Settings $settings Settings manager
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->initEngines();
    }

    /**
     * Initialize available engines
     */
    private function initEngines(): void
    {
        $this->engines = [
            'imagick' => new ImagickEngine(),
        ];

        /**
         * Filter available engines
         *
         * @param EngineInterface[] $engines Available engines
         */
        $filtered = apply_filters('rapls_pdf_image_creator_engines', $this->engines);

        // Engines only: a listener answering something that is not a list
        // was a TypeError on this property, and an entry that is not an
        // engine a fatal at the first isAvailable() (review after 1.4.31).
        // Not a list, the plugin's own stay; a list keeps its engines, none
        // if a listener means none.
        if (is_array($filtered)) {
            $this->engines = array_filter($filtered, static function ($engine): bool {
                return $engine instanceof EngineInterface;
            });
        }
    }

    /**
     * Get available engines
     *
     * @return EngineInterface[]
     */
    public function getEngines(): array
    {
        return $this->engines;
    }

    /**
     * Option recording why the last generation attempt had no engine
     *
     * Only ever holds a status code from getAvailabilityStatus(), so it is
     * safe to display and cheap to read on an admin page.
     */
    public const UNAVAILABLE_OPTION = 'rapls_pic_engine_unavailable';

    /**
     * Option holding the last generation failure, for the Status tab
     *
     * Holds a code, the message, which attachment it was and when. Overwritten
     * on each failure and deleted on the next success, so it answers "is
     * anything wrong right now" rather than accumulating a log.
     */
    public const LAST_FAILURE_OPTION = 'rapls_pic_last_failure';

    /**
     * Explain whether thumbnails can be generated on this server, and why not
     *
     * @return array{code: string, label: string, summary: string, action: string, detail: string}
     */
    public function getAvailabilityStatus(): array
    {
        foreach ($this->engines as $engine) {
            $status = $engine->getAvailabilityStatus();
            if ('ok' === $status['code']) {
                return $status;
            }
        }

        // No engine said yes. Report the first one's reason; with a single
        // engine that is the whole story, and with more it is still the most
        // useful thing to show.
        foreach ($this->engines as $engine) {
            return $engine->getAvailabilityStatus();
        }

        return [
            'code' => FailureCode::NO_ENGINE,
            'label' => __('No conversion engine', 'rapls-pdf-image-creator'),
            'summary' => __('No PDF conversion engine is registered.', 'rapls-pdf-image-creator'),
            'action' => '',
            'detail' => '',
        ];
    }

    /**
     * Get available engine for conversion
     *
     * @return EngineInterface|null
     */
    public function getAvailableEngine(): ?EngineInterface
    {
        // Try each engine (currently only Imagick)
        foreach ($this->engines as $engine) {
            if ($engine->isAvailable()) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * Check if PDF has a thumbnail
     *
     * @param int $pdfId PDF attachment ID
     * @return bool
     */
    public function hasThumbnail(int $pdfId): bool
    {
        return $this->getThumbnailId($pdfId) !== null;
    }

    /**
     * Get thumbnail ID for a PDF
     *
     * Only an attachment counts, as limitPdfsToThumbnailed() asks in SQL: a
     * key naming a post or a page let the PDF through here and not there
     * (review of 1.4.33).
     *
     * @param int $pdfId PDF attachment ID
     * @return int|null Thumbnail attachment ID or null
     */
    public function getThumbnailId(int $pdfId): ?int
    {
        // First check our custom meta
        $thumbnailId = get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true);
        // Verify the thumbnail still exists. A key naming one that is gone is
        // left as it is: this is asked on the front end -- shortcodes, image
        // filters -- and deleting it there wrote to the database, and
        // emptied the object cache's meta for the PDF, from a page view
        // (review of 1.4.35). The next generation writes the key again, and
        // uninstalling removes it.
        if ($thumbnailId && self::isAttachment($thumbnailId)) {
            return (int) $thumbnailId;
        }

        // Fall back to _thumbnail_id (WordPress standard)
        $thumbnailId = get_post_meta($pdfId, '_thumbnail_id', true);
        if ($thumbnailId && self::isAttachment($thumbnailId)) {
            return (int) $thumbnailId;
        }

        return null;
    }

    /**
     * Whether an id names an attachment that exists
     *
     * @param mixed $id
     */
    private static function isAttachment($id): bool
    {
        $post = get_post((int) $id);

        return is_object($post) && 'attachment' === ($post->post_type ?? '');
    }

    /**
     * Get thumbnail URL
     *
     * @param int $pdfId PDF attachment ID
     * @param string $size Image size
     * @return string|null Thumbnail URL or null
     */
    public function getThumbnailUrl(int $pdfId, string $size = 'thumbnail'): ?string
    {
        $thumbnailId = $this->getThumbnailId($pdfId);
        if (!$thumbnailId) {
            return null;
        }

        $url = wp_get_attachment_image_url($thumbnailId, $size);
        return $url ?: null;
    }

    /**
     * Get thumbnail image HTML
     *
     * @param int $pdfId PDF attachment ID
     * @param string $size Image size
     * @param array<string, mixed> $attr Image attributes
     * @return string Image HTML or empty string
     */
    public function getThumbnailImage(int $pdfId, string $size = 'thumbnail', array $attr = []): string
    {
        $thumbnailId = $this->getThumbnailId($pdfId);
        if (!$thumbnailId) {
            return '';
        }

        $defaultAttr = [
            'class' => 'pic-thumbnail',
            'alt' => get_the_title($pdfId),
        ];

        $attr = array_merge($defaultAttr, $attr);

        /**
         * Filter thumbnail image attributes
         *
         * @param array $attr Image attributes
         * @param int $pdfId PDF attachment ID
         * @param int $thumbnailId Thumbnail attachment ID
         */
        $attr = apply_filters('rapls_pdf_image_creator_thumbnail_image_attributes', $attr, $pdfId, $thumbnailId);

        return wp_get_attachment_image($thumbnailId, $size, false, $attr);
    }

    /** The file this request reserved and has not yet added to the Media Library. */
    private static ?string $held = null;

    /** Whether the shutdown clean-up is registered (once a request). */
    private static bool $holding = false;

    /**
     * Remember the reserved file until it is in the Media Library; null lets it go
     *
     * A request that dies from max_execution_time while drawing -- a large
     * page at 600 dpi on a shared host -- runs no catch and no finally, and
     * left the reserved file behind, empty or half written: every later
     * attempt then took a "-1", "-2" beside it (review of 1.4.32). Shutdown
     * functions still run after that fatal error.
     */
    private static function holdUntilAdded(?string $path): void
    {
        self::$held = $path;

        if (null === $path || self::$holding) {
            return;
        }

        self::$holding = true;
        register_shutdown_function([self::class, 'releaseHeld']);
    }

    /**
     * At shutdown: delete the reserved file a request did not get to add
     *
     * @internal Public only for register_shutdown_function().
     */
    public static function releaseHeld(): void
    {
        $path = self::$held;
        self::$held = null;

        if (null !== $path && is_file($path)) {
            wp_delete_file($path);
        }
    }

    /**
     * Generate thumbnail for a PDF
     *
     * @param int $pdfId PDF attachment ID
     * @param bool $force Force regeneration
     * @return int|null Thumbnail attachment ID or null on failure
     */
    public function generate(int $pdfId, bool $force = false): ?int
    {
        // A new attempt: what the last one for this PDF came to is no longer
        // the answer -- from the first line, so nothing that throws on the
        // way leaves the last attempt's outcome standing for this one
        // (R61-02, R62-02).
        unset($this->outcomes[$pdfId]);

        // Check if PDF exists
        $pdf = get_post($pdfId);
        if (!$pdf || $pdf->post_type !== 'attachment') {
            return $this->fail($pdfId, FailureCode::NOT_A_PDF, __('No such attachment.', 'rapls-pdf-image-creator'));
        }

        // Check if it's a PDF
        $mimeType = get_post_mime_type($pdfId);
        if ($mimeType !== 'application/pdf') {
            return $this->fail($pdfId, FailureCode::NOT_A_PDF, __('Not a PDF file.', 'rapls-pdf-image-creator'));
        }

        // Check if thumbnail already exists
        if (!$force && $this->hasThumbnail($pdfId)) {
            $existing = $this->getThumbnailId($pdfId);
            $this->outcomes[$pdfId] = ['ok' => true, 'thumbnail_id' => $existing, 'code' => '', 'message' => '', 'kept_previous' => null];

            return $existing;
        }

        // The thumbnail being replaced, let go of only once the new one is in
        // place. It used to be deleted here, before the PDF was even looked
        // at, and any failure after that -- a missing file, no engine, a
        // render error -- left the PDF with no thumbnail at all (R55-03).
        $previousId = $force ? $this->getThumbnailId($pdfId) : null;
        $replacing = null !== $previousId;

        // What this plugin's own key held, as it was. $previousId is what
        // getThumbnailId() shows, which falls back to `_thumbnail_id`: put
        // back into this key after a failure, a featured image chosen by hand
        // became "the thumbnail this plugin made", and the add-on's list of
        // PDFs without one no longer showed the PDF (R57-01).
        $previousOwn = (string) get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true);

        // Get PDF file path
        $pdfPath = get_attached_file($pdfId);
        if (!$pdfPath || !file_exists($pdfPath)) {
            return $this->fail(
                $pdfId,
                FailureCode::SOURCE_MISSING,
                __('The PDF file is missing from the uploads folder.', 'rapls-pdf-image-creator')
            );
        }

        // Get available engine
        $engine = $this->getAvailableEngine();
        if (!$engine) {
            // This used to return in silence, which is the worst way to fail:
            // the thumbnail simply never appears and nothing anywhere says
            // why. Leave a trace, and record it for the admin notice.
            $status = $this->getAvailabilityStatus();

            // Bulk Generate calls this in a loop; only write when the answer
            // actually changed.
            // Autoloaded: the admin notice asks for it on every admin page,
            // and while it is there the answer is in memory (review after
            // 1.4.33). It is only there while this server cannot draw.
            if (get_option(self::UNAVAILABLE_OPTION) !== $status['code']) {
                update_option(self::UNAVAILABLE_OPTION, $status['code'], true);
            }

            return $this->fail($pdfId, $status['code'], $status['summary']);
        }

        // Prepare output path: next to the PDF. (wp_upload_dir() was asked
        // here too and never read -- a run of every upload_dir filter for
        // nothing; Codex review of 1.4.26, 5.)
        $pdfDir = dirname($pdfPath);
        $pdfBasename = pathinfo($pdfPath, PATHINFO_FILENAME);
        // The format, filtered once and before the name is chosen: the name's
        // extension decides the attachment's MIME type, and a filter that
        // turned JPEG into PNG after the name was chosen got PNG bytes in a
        // .jpg file registered as image/jpeg (R58-05).
        $format = strtolower((string) apply_filters('rapls_pdf_image_creator_thumbnail_format', $this->settings->getFormat(), $pdfId));
        $format = in_array($format, ['png', 'webp'], true) ? $format : 'jpeg';
        $extension = 'jpeg' === $format ? 'jpg' : $format;

        $outputFilename = $pdfBasename . '-pdf-thumbnail.' . $extension;

        // A replacement needs a name of its own.
        //
        // Deleting the old thumbnail frees its filename, so the loop below
        // hands the new file the name the old one had, and every browser that
        // cached the old image goes on showing it. That is worst for exactly
        // the people who need it least: someone regenerating a thumbnail is
        // usually replacing one that came out wrong. Measured on a live site
        // -- a blank thumbnail, correctly regenerated after the server was
        // fixed, still looked blank until the file was given a different name.
        //
        // Only on a forced regeneration, so a first run keeps the plain name.
        if ($replacing) {
            $outputFilename = $pdfBasename . '-pdf-thumbnail-' . dechex(time()) . '.' . $extension;
        }

        $outputPath = $pdfDir . '/' . $outputFilename;

        // Ensure unique filename -- by taking it, not by looking. Two requests
        // regenerating the same PDF in the same second both saw the name
        // free, drew into the same file and registered it twice; deleting
        // either attachment then took the other's image (O56-01). fopen('x')
        // creates the file only if nobody has, so each request gets a name of
        // its own. A folder that cannot be written to stops the loop, and the
        // engine says why.
        $counter = 1;
        $stem = pathinfo($outputFilename, PATHINFO_FILENAME);
        $reserved = false;
        while (true) {
            $handle = @fopen($outputPath, 'x');

            if (false !== $handle) {
                fclose($handle);
                $reserved = true;
                self::holdUntilAdded($outputPath);
                break;
            }

            if (!file_exists($outputPath)) {
                break;
            }

            $outputFilename = $stem . '-' . $counter . '.' . $extension;
            $outputPath = $pdfDir . '/' . $outputFilename;
            $counter++;
        }

        // From here until the image is in the Media Library, the file under
        // that name is this request's. A listener or an engine that threw
        // left the empty file behind, and the next attempt took "-1" beside
        // it (R58-04).
        try {
        /**
         * Action before generating thumbnail
         *
         * @param int $pdfId PDF attachment ID
         * @param string $pdfPath PDF file path
         */
        do_action('rapls_pdf_image_creator_before_generate', $pdfId, $pdfPath);

        // Build conversion options.
        //
        // attachment_id and source_path are context, not settings: they are not
        // filtered and the engine does not read them. They are there so that a
        // listener on rapls_pdf_image_creator_before_resize can tell which
        // attachment it is looking at -- the engine signature is (path, path,
        // options) and carries no attachment anywhere else.
        //
        // The numbers are made whole numbers: a filter answering "800" (a
        // post meta value) or 512.0 (a width times a half) reached Imagick's
        // int parameters as a TypeError, which nothing caught, and the upload
        // request died (review after 1.4.31).
        $options = [
            'attachment_id' => $pdfId,
            'source_path' => $pdfPath,
            'page' => (int) apply_filters('rapls_pdf_image_creator_thumbnail_page', $this->settings->getPage(), $pdfId),
            'max_width' => (int) apply_filters('rapls_pdf_image_creator_thumbnail_max_width', $this->settings->getMaxWidth(), $pdfId),
            'max_height' => (int) apply_filters('rapls_pdf_image_creator_thumbnail_max_height', $this->settings->getMaxHeight(), $pdfId),
            'resolution' => (int) apply_filters('rapls_pdf_image_creator_thumbnail_resolution', $this->settings->getResolution(), $pdfId),
            'quality' => (int) apply_filters('rapls_pdf_image_creator_thumbnail_quality', $this->settings->getQuality(), $pdfId),
            'format' => $format,
            'bgcolor' => apply_filters('rapls_pdf_image_creator_thumbnail_bgcolor', $this->settings->getBgColor(), $pdfId),
        ];

        // Convert PDF to image
        $result = $engine->convert($pdfPath, $outputPath, $options);
        } catch (\Throwable $e) {
            if ($reserved) {
                wp_delete_file($outputPath);
            }
            self::holdUntilAdded(null);

            throw $e;
        }

        if (!$result->isSuccess()) {
            // The name taken above is this request's, and so is whatever the
            // engine left under it.
            if ($reserved) {
                wp_delete_file($outputPath);
            }
            self::holdUntilAdded(null);

            return $this->fail(
                $pdfId,
                $result->getCode() ?: FailureCode::RENDER_ERROR,
                (string) $result->getError(),
                $result
            );
        }

        // Create attachment for thumbnail
        $thumbnailId = $this->createThumbnailAttachment($pdfId, $outputPath, $outputFilename);
        // In the Media Library, or cleaned up by createThumbnailAttachment().
        self::holdUntilAdded(null);

        if (!$thumbnailId) {
            // createThumbnailAttachment() has already cleaned up what it made
            // -- the file with the attachment, or the file alone when there
            // was none. Deleted here as well, the file went even when the
            // attachment could not be, and it pointed at nothing (R58-01).
            return $this->fail(
                $pdfId,
                FailureCode::WRITE_FAILED,
                __('The image was rendered but could not be added to the Media Library.', 'rapls-pdf-image-creator')
            );
        }

        // Asked to fill a gap, and another request -- the upload hook and Bulk
        // Generate, at once -- has filled it while this one drew: theirs
        // stands, and this image goes. Written over it, theirs stayed in the
        // Media Library as nobody's, with this plugin's marks, so hidden
        // (review after 1.4.31). Read from the database, not this request's
        // cache, which still holds the empty answer from the start.
        if (!$force) {
            wp_cache_delete($pdfId, 'post_meta');
            $meanwhile = $this->getThumbnailId($pdfId);

            if (null !== $meanwhile && $meanwhile !== $thumbnailId) {
                $this->discardNewThumbnail($thumbnailId, $pdfId, 'was not needed: another request made one first');
                $this->outcomes[$pdfId] = ['ok' => true, 'thumbnail_id' => $meanwhile, 'code' => '', 'message' => '', 'kept_previous' => null];

                return $meanwhile;
            }
        }

        // Store thumbnail ID in PDF meta, and read it back: not stored, the
        // new image is nobody's, and the old one is still the PDF's.
        update_post_meta($pdfId, self::THUMBNAIL_META_KEY, $thumbnailId);

        if ((int) get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true) !== $thumbnailId) {
            $this->discardNewThumbnail($thumbnailId, $pdfId);

            return $this->fail(
                $pdfId,
                FailureCode::WRITE_FAILED,
                __('The image was rendered but could not be recorded as this PDF\'s thumbnail.', 'rapls-pdf-image-creator')
            );
        }

        // Set as featured image if enabled -- and read back, as above. Not
        // stored, the old image was let go of below all the same, and the PDF
        // was left with no featured image while this reported success
        // (R56-02). So the PDF goes back to the old thumbnail, and the new
        // image goes only once nothing names it.
        if ($this->settings->shouldSetFeatured()) {
            update_post_meta($pdfId, '_thumbnail_id', $thumbnailId);

            if ((int) get_post_meta($pdfId, '_thumbnail_id', true) !== $thumbnailId) {
                if ('' !== $previousOwn) {
                    update_post_meta($pdfId, self::THUMBNAIL_META_KEY, $previousOwn);
                } else {
                    delete_post_meta($pdfId, self::THUMBNAIL_META_KEY);
                }

                // Put back: the new image is nobody's. Not put back: the PDF
                // still names it, and it stays with the old one beside it.
                if ((int) get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true) !== $thumbnailId) {
                    $this->discardNewThumbnail($thumbnailId, $pdfId);
                }

                return $this->fail(
                    $pdfId,
                    FailureCode::WRITE_FAILED,
                    __('The image was rendered but could not be set as this PDF\'s featured image, so the previous thumbnail was kept.', 'rapls-pdf-image-creator')
                );
            }
        }

        // Only now that the new one is the PDF's. An image this PDF did not
        // make -- a featured image chosen by hand, which getThumbnailId()
        // falls back to -- is neither deleted nor unlinked: the new thumbnail
        // is found first, and the choice was not this plugin's (R55-02).
        // Asked once: the content lookup reads posts, and asking again whether
        // the image was "used elsewhere" ran it a second time (review of
        // 1.4.36).
        $released = null !== $previousId && $previousId !== $thumbnailId && $this->isOwnThumbnail($previousId, $pdfId)
            ? $this->releaseThumbnail($pdfId, $previousId)
            : null;
        if (self::RELEASE_FAILED === $released) {
            // The new one is in place, so this is not a failure; but the old
            // image is now linked from nothing and stays. Said, so it can be
            // found (R58-03).
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('Rapls PDF Image Creator: the previous thumbnail #%d of PDF #%d could not be deleted after it was replaced; it is still in the Media Library.', $previousId, $pdfId));
        }
        // Kept -- a post shows it, or whether one does could not be told --
        // and said on the screen that regenerated it: the post still shows
        // the old picture, which nobody would otherwise know (review after
        // 1.4.37).
        $keptPrevious = self::RELEASE_KEPT === $released ? $previousId : null;

        /**
         * Action after generating thumbnail
         *
         * @param int $thumbnailId Thumbnail attachment ID
         * @param int $pdfId PDF attachment ID
         * @param ConversionResult $result Conversion result
         */
        // Something worked. Whatever the Status tab was complaining about is
        // no longer true.
        if (false !== get_option(self::LAST_FAILURE_OPTION, false)) {
            delete_option(self::LAST_FAILURE_OPTION);
        }

        // Recorded before anyone is told, so what was done does not depend on
        // who listens: a listener that throws stops the notification, not
        // the outcome (R58-04, R60-02). The exception is not caught here --
        // WordPress's hooks do not tidy up after one, and a request carrying
        // on past it would carry that state too (R60-03).
        $this->outcomes[$pdfId] = ['ok' => true, 'thumbnail_id' => $thumbnailId, 'code' => '', 'message' => '', 'kept_previous' => $keptPrevious];

        do_action('rapls_pdf_image_creator_after_generate', $thumbnailId, $pdfId, $result);

        return $thumbnailId;
    }

    /**
     * Record a failure, announce it, and return null.
     *
     * Every path out of generate() that does not produce a thumbnail comes
     * through here. Before 1.4.0 most of them returned null in silence, which
     * meant the thumbnail simply never appeared and nothing anywhere said why
     * -- the single most common support question this plugin gets.
     *
     * @param int                   $pdfId   Attachment the attempt was for.
     * @param string                $code    See FailureCode.
     * @param string                $message Sentence for a person, already translated.
     * @param ConversionResult|null $result  The engine's result, when there was one.
     * @return null Always. Callers return this straight back.
     */
    private function fail(int $pdfId, string $code, string $message, ?ConversionResult $result = null)
    {
        // NOT_A_PDF is not recorded. It means someone asked for a thumbnail of
        // something that was never a PDF -- a template call with the wrong ID,
        // usually -- and putting that on the Status tab would be alarming a
        // site owner about their theme's bug.
        if (FailureCode::NOT_A_PDF !== $code) {
            update_option(
                self::LAST_FAILURE_OPTION,
                [
                    'code' => $code,
                    'message' => $message,
                    'pdf_id' => $pdfId,
                    'time' => time(),
                ],
                false
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'Rapls PDF Image Creator: generation failed for #%d (%s) - %s',
                $pdfId,
                $code,
                $message
            ));
        }

        /**
         * Action when generation fails.
         *
         * @since 1.0.0
         * @since 1.4.0 The $code and $result arguments were added, and the
         *              action fires on every failure rather than only on a
         *              conversion error.
         *
         * @param string                $message Human-readable error.
         * @param int                   $pdfId   PDF attachment ID.
         * @param string                $code    Machine-readable reason. See FailureCode.
         * @param ConversionResult|null $result  Engine result, or null when the
         *                                       failure happened before the engine ran.
         */
        // Recorded before the action, as for a success: a listener that
        // throws -- or runs first and throws, before one that was listening
        // for the reason -- does not change what failed (R59-02, R60-02).
        $this->outcomes[$pdfId] = ['ok' => false, 'thumbnail_id' => null, 'code' => $code, 'message' => $message, 'kept_previous' => null];

        do_action('rapls_pdf_image_creator_generation_failed', $message, $pdfId, $code, $result);

        return null;
    }

    /**
     * What the last generate() for this PDF came to, in this request
     *
     * For code that needs the reason as data rather than from an action,
     * whose listeners can run in any order and throw: `ok`, `thumbnail_id`,
     * `code` (see FailureCode), `message`, and `kept_previous`: the old
     * thumbnail's ID when a forced regeneration kept it as an ordinary
     * image, because a post shows it. Null before any attempt, or when the
     * attempt ended in an exception before it came to anything.
     *
     * @since 1.4.17
     * @since 1.4.39 kept_previous.
     * @return array{ok: bool, thumbnail_id: int|null, code: string, message: string, kept_previous: int|null}|null
     */
    public function outcomeFor(int $pdfId): ?array
    {
        return $this->outcomes[$pdfId] ?? null;
    }

    /** keptReasonFor(): a post's content shows the image. */
    public const KEPT_SHOWN = 'shown';
    /** keptReasonFor(): a post uses it as its featured image. */
    public const KEPT_FEATURED = 'featured';
    /** keptReasonFor(): another PDF -- a translation's copy -- names it. */
    public const KEPT_SHARED = 'shared';
    /** keptReasonFor(): whether anything uses it could not be told. */
    public const KEPT_UNKNOWN = 'unknown';

    /** Why the last release of each PDF's image kept it, by PDF ID. */
    private $keptReasons = [];

    /**
     * Why the last image this PDF let go of was kept, in this request
     *
     * One of the KEPT_* constants; null when nothing was kept. A
     * regeneration that kept the old thumbnail said a post showed it
     * whatever the reason, also when a featured image, another PDF or a
     * lookup that did not answer had kept it (review after 1.4.38).
     *
     * @since 1.4.39
     */
    public function keptReasonFor(int $pdfId): ?string
    {
        return $this->keptReasons[$pdfId] ?? null;
    }

    /**
     * Remove a thumbnail just made that the PDF did not take
     *
     * Deleted with its file. Another plugin can refuse the deletion
     * (pre_delete_attachment); the image then stays, linked from nothing,
     * and that is said, as for an old thumbnail that could not be deleted
     * (R58-03, R59-01). Its file is never deleted apart from it.
     */
    private function discardNewThumbnail(int $thumbnailId, int $pdfId, string $why = 'could not be recorded'): void
    {
        if (!$this->deleteAttachment($thumbnailId)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('Rapls PDF Image Creator: the new thumbnail #%d of PDF #%d %s, and could not be deleted either; it is still in the Media Library.', $thumbnailId, $pdfId, $why));
        }
    }

    /**
     * What went wrong the last time a thumbnail was attempted
     *
     * @return array{code: string, message: string, pdf_id: int, time: int}|null
     */
    public function getLastFailure(): ?array
    {
        $stored = get_option(self::LAST_FAILURE_OPTION, false);

        if (!is_array($stored) || empty($stored['code'])) {
            return null;
        }

        return [
            'code' => (string) $stored['code'],
            'message' => (string) ($stored['message'] ?? ''),
            'pdf_id' => (int) ($stored['pdf_id'] ?? 0),
            'time' => (int) ($stored['time'] ?? 0),
        ];
    }

    /**
     * Whether generate() made this image from this PDF
     *
     * createAttachment() tags every thumbnail with both, and has since the
     * first release.
     */
    public function isOwnThumbnail(int $thumbnailId, int $pdfId): bool
    {
        return '' !== (string) get_post_meta($thumbnailId, '_rapls_pic_is_thumbnail', true)
            && (int) get_post_meta($thumbnailId, '_rapls_pic_source_pdf', true) === $pdfId;
    }

    /**
     * Whether anything but this PDF names this image as its thumbnail
     *
     * This plugin's own meta on another attachment, or `_thumbnail_id` on any
     * post. When the database does not answer, the answer is yes: an image
     * kept is recoverable, an image deleted is not.
     */
    private function namedElsewhere(int $thumbnailId, int $pdfId): bool
    {
        global $wpdb;

        // No database to ask is the same answer as a database that did not
        // answer: the image is kept.
        if (!is_object($wpdb) || !isset($wpdb->postmeta) || !method_exists($wpdb, 'get_var')) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value = %s AND post_id <> %d LIMIT 1",
            self::THUMBNAIL_META_KEY,
            '_thumbnail_id',
            (string) $thumbnailId,
            $pdfId
        ));

        return null !== $found || '' !== (string) $wpdb->last_error;
    }

    /**
     * Whether another attachment -- a translation's copy of the PDF -- names
     * this image as its thumbnail
     *
     * Another PDF only: an audio file's or a video's cover image names its
     * picture by `_thumbnail_id` too, and was counted as another PDF's
     * thumbnail, so the image stayed marked and hidden while it was in use
     * (review after 1.4.38).
     *
     * Such an image is still a thumbnail, that PDF's, and keeps its two
     * markers, its source now naming that PDF: left naming this one, the
     * other PDF's own regeneration or deletion did not take it for its own,
     * and it stayed marked and hidden with nothing naming it (review of
     * 1.4.39). One only a post's featured image names is not anybody's
     * thumbnail once this PDF lets go of it, and becomes an ordinary image,
     * as the settings screen says; it stayed marked, hidden in the Media
     * Library by "Hide Generated Images" while a post showed it (review of
     * 1.4.36).
     *
     * @return int|null The other PDF's ID, 0 when there is none, null when
     *                  the database did not answer.
     */
    private function namedByAttachment(int $thumbnailId, int $pdfId): ?int
    {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->postmeta, $wpdb->posts) || !method_exists($wpdb, 'get_var')) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT meta.post_id FROM {$wpdb->postmeta} meta INNER JOIN {$wpdb->posts} named ON named.ID = meta.post_id AND named.post_type = 'attachment' AND named.post_mime_type = 'application/pdf'
             WHERE meta.meta_key IN (%s, %s) AND meta.meta_value = %s AND meta.post_id <> %d LIMIT 1",
            self::THUMBNAIL_META_KEY,
            '_thumbnail_id',
            (string) $thumbnailId,
            $pdfId
        ));

        if ('' !== (string) $wpdb->last_error) {
            return null;
        }

        return (int) $found;
    }

    /**
     * Whether some post's content shows this image
     *
     * An image or gallery block given the PDF, and the classic editor's
     * "Insert into post", put the thumbnail's own address into the post. A
     * forced regeneration or deleting the PDF deleted the file, and the
     * picture in the post became a broken link (review of 1.4.35).
     *
     * ContentUse makes the test, the same one uninstall.php makes: the file
     * name in any size, before and after an edit, as it is and
     * percent-encoded, compared whole; or the image's ID in a "wp-image-"
     * class, an image block, a gallery block or a [gallery] shortcode -- or
     * the ID wherever an ID could stand, as page builders keep an image by
     * ID alone (review after 1.4.38). SQL only narrows the posts read, with
     * LIKE alone; PHP decides. "%name%" in SQL alone kept
     * "report-pdf-thumbnail" because a post showed
     * "annual-report-pdf-thumbnail", and nothing found a gallery naming the
     * image by ID (review of 1.4.36).
     *
     * Revisions, auto-drafts and attachments are not read; a trashed post
     * is, as it can come back (review after 1.4.37). The content and the
     * excerpt are, and the widgets (ContentUse::WIDGET_OPTIONS): not other
     * options or a page builder's meta kept outside them.
     *
     * Each image let go of reads the posts table once, LIKE with a leading
     * "%" using no index; Bulk Generate with "Include PDFs that already have
     * thumbnails" does so once per PDF. Not cached across PDFs: a post
     * saved between two of them would be missed, and the image it shows
     * deleted (review of 1.4.39).
     * It runs only when an image of this plugin's own is about to be
     * deleted: a forced regeneration, or deleting a PDF.
     *
     * @return bool|null Null when the database did not answer.
     */
    private function shownInContent(int $thumbnailId): ?bool
    {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->posts) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'esc_like')) {
            return null;
        }

        class_exists(ContentUse::class) || require_once __DIR__ . '/ContentUse.php';

        $file = (string) get_post_meta($thumbnailId, '_wp_attached_file', true);
        if ('' === $file) {
            // No file recorded -- an import, a cleanup plugin: the address
            // WordPress gave it, for its name. Without a name a post
            // showing it by address could not be found, and it was deleted
            // under the post (review after 1.4.38). With neither, its ID
            // alone is looked for.
            $image = get_post($thumbnailId);
            $guid = is_object($image) ? (string) ($image->guid ?? '') : '';
            $file = (string) (function_exists('wp_parse_url') ? wp_parse_url($guid, PHP_URL_PATH) : parse_url($guid, PHP_URL_PATH)); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- core's own where it is loaded
            // An address is percent-encoded; the name in a post is compared
            // decoded, as _wp_attached_file holds it (review of 1.4.38's
            // fixes).
            $file = rawurldecode($file);
        }
        $stems = ContentUse::stemsOf($file, get_post_meta($thumbnailId, '_wp_attachment_backup_sizes', true));
        // The widgets first: one read of a few options (review of 1.4.39).
        if (ContentUse::shows(ContentUse::widgetContent(), $thumbnailId, $stems)) {
            return true;
        }
        // ContentUse::candidate(), as SQL, LIKE only. The ID beside each
        // character that can stand right before it (ContentUse::idLikesSql():
        // digits and punctuation, the same in any collation). Each name
        // fragment, LOWER() of each column on its own (a case-sensitive
        // collation, utf8mb4_bin or utf8mb4_ja_0900_as_cs, missed
        // "Report-pdf-thumbnail.jpg" and the image was deleted -- review
        // after 1.4.37; CONCAT_WS() of the two failed with "Illegal mix of
        // collations" where they differ -- review of 1.4.37's fixes).
        // No REGEXP: MySQL 8 runs one through ICU under regexp_time_limit,
        // and a post of some megabytes failed the query -- the image kept,
        // but kept every time; MariaDB runs it through PCRE, which past its
        // own limits only warns and counts the row as not matching -- the
        // post not read, and the image it showed deleted (review of 1.4.39).
        $likes = [];
        $patterns = [];
        $idLikes = ContentUse::idLikesSql($thumbnailId, [$wpdb, 'esc_like']);
        // Every one of those holds the ID's digits, so a column without them
        // is passed over in one scan first; a necessary condition added, the
        // answer unchanged (review of 1.4.39's fixes).
        $idDigits = '%' . $wpdb->esc_like((string) $thumbnailId) . '%';
        foreach (['post_content', 'post_excerpt'] as $column) {
            $likes[] = "({$column} LIKE %s AND (" . implode(' OR ', array_fill(0, count($idLikes), "{$column} LIKE %s")) . '))';
            $patterns[] = $idDigits;
            array_push($patterns, ...$idLikes);
        }
        foreach (ContentUse::fragments($stems) as $fragment) {
            $pattern = '%' . $wpdb->esc_like($fragment) . '%';
            $likes[] = 'LOWER(post_content) LIKE %s';
            $likes[] = 'LOWER(post_excerpt) LIKE %s';
            $patterns[] = $pattern;
            $patterns[] = $pattern;
        }
        $likes = implode(' OR ', $likes);
        $skipTypes = "'" . implode("', '", ContentUse::SKIP_TYPES) . "'";
        $skipStatuses = "'" . implode("', '", ContentUse::SKIP_STATUSES) . "'";

        // From before the first post: an ID starts at 1.
        $after = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constants and placeholders only
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
                 WHERE ID > %d AND post_type NOT IN ({$skipTypes}) AND post_status NOT IN ({$skipStatuses}) AND ({$likes})
                 ORDER BY ID LIMIT 100",
                $after,
                ...$patterns
            ), defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');

            if (!is_array($rows) || '' !== (string) $wpdb->last_error) {
                return null;
            }

            foreach ($rows as $row) {
                $after = (int) ($row['ID'] ?? 0);
                $shown = (string) ($row['post_content'] ?? '') . "\n" . (string) ($row['post_excerpt'] ?? '');
                if (ContentUse::shows($shown, $thumbnailId, $stems)) {
                    return true;
                }
            }
        } while (100 === count($rows));

        return false;
    }

    /**
     * Delete thumbnail for a PDF
     *
     * Only an image generated from this PDF is deleted. getThumbnailId() also
     * answers with `_thumbnail_id`, which another plugin -- or a site moved
     * over from one -- can point at any image in the library, one embedded
     * in posts among them; and a translation plugin can copy this plugin's
     * own meta from another PDF, so it names that PDF's thumbnail. Both were
     * deleted for good, file and all, by a forced regeneration or by
     * deleting the PDF. They are now only unlinked from this PDF.
     *
     * @param int  $pdfId    PDF attachment ID
     * @param bool $pdfGoing The PDF itself is being deleted.
     * @return bool Whether an image was deleted
     */
    public function deleteThumbnail(int $pdfId, bool $pdfGoing = false): bool
    {
        $thumbnailId = $this->getThumbnailId($pdfId);

        if (!$thumbnailId) {
            return false;
        }

        return self::RELEASE_DELETED === $this->releaseThumbnail($pdfId, $thumbnailId, $pdfGoing);
    }

    /** releaseThumbnail(): deleted, file and all. */
    private const RELEASE_DELETED = 'deleted';
    /** releaseThumbnail(): kept, and only let go of. */
    private const RELEASE_KEPT = 'kept';
    /** releaseThumbnail(): meant to be deleted, and still there. */
    private const RELEASE_FAILED = 'failed';

    /**
     * Let this PDF go of one image, and delete it if nothing else needs it
     *
     * Each key is removed only while it still names this image. The PDF's
     * `_thumbnail_id` could name another image altogether -- a featured image
     * chosen by hand, with "Set as featured image" off -- and was removed
     * whatever it named (R55-02).
     *
     * @param bool $pdfGoing The PDF itself is being deleted.
     * @return string One of the RELEASE_* constants.
     */
    private function releaseThumbnail(int $pdfId, int $thumbnailId, bool $pdfGoing = false): string
    {
        $result = self::RELEASE_KEPT;
        unset($this->keptReasons[$pdfId]);

        // Made from this PDF is not enough when something else shows it too:
        // a translation's copy of this PDF that shares its thumbnail, or a
        // post that took it as its featured image. Deleting it left them
        // pointing at an image that was gone; only this PDF lets go of it.
        if ($this->isOwnThumbnail($thumbnailId, $pdfId)) {
            if ($this->namedElsewhere($thumbnailId, $pdfId)) {
                // Another PDF's thumbnail too: marked, as that PDF's. Only a
                // post's featured image: an ordinary image. Not known: an
                // ordinary image, said -- kept and visible is recoverable,
                // marked with nothing pointing at it is lost in the library.
                $byAttachment = $this->namedByAttachment($thumbnailId, $pdfId);
                $this->keptReasons[$pdfId] = $byAttachment > 0 ? self::KEPT_SHARED : (0 === $byAttachment ? self::KEPT_FEATURED : self::KEPT_UNKNOWN);
                if ($byAttachment > 0) {
                    update_post_meta($thumbnailId, '_rapls_pic_source_pdf', $byAttachment);
                    if ($pdfGoing) {
                        // Attached to the PDF that names it now, not left on
                        // one that is gone (review of 1.4.39's fixes).
                        $this->attachTo($thumbnailId, $pdfId, $byAttachment);
                    }
                } else {
                    $this->makeOrdinary($thumbnailId, $pdfId, $pdfGoing);
                }
                if (null === $byAttachment) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(sprintf('Rapls PDF Image Creator: could not tell whether another PDF uses image #%d; PDF #%d let go of it and it was kept as an ordinary image.', $thumbnailId, $pdfId));
                }
            } else {
                $shown = $this->shownInContent($thumbnailId);
                if (null === $shown) {
                    $this->keptReasons[$pdfId] = self::KEPT_UNKNOWN;
                    // Not known: kept as an ordinary image, and said. Left
                    // marked and linked "for the next attempt", it was
                    // neither: the PDF already names its new thumbnail, or
                    // is being deleted, and the image stayed hidden with
                    // nothing pointing at it (review of 1.4.36).
                    $this->makeOrdinary($thumbnailId, $pdfId, $pdfGoing);
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(sprintf('Rapls PDF Image Creator: could not tell whether a post shows image #%d; PDF #%d let go of it and it was kept as an ordinary image.', $thumbnailId, $pdfId));
                } elseif ($shown) {
                    $this->keptReasons[$pdfId] = self::KEPT_SHOWN;
                    // Shown in a post's content: kept, and made an ordinary
                    // image -- visible in the Media Library with "Hide
                    // Generated Images" on, and no longer this PDF's to
                    // delete later.
                    $this->makeOrdinary($thumbnailId, $pdfId, $pdfGoing);
                } elseif ($this->deleteAttachment($thumbnailId)) {
                    $result = self::RELEASE_DELETED;
                } elseif ($pdfGoing) {
                    // Refused (pre_delete_attachment) while the PDF itself
                    // goes: there is no next attempt to find it, and it
                    // stayed marked, hidden by "Hide Generated Images",
                    // attached to nothing (review after 1.4.38). Kept as an
                    // ordinary image, and said.
                    $this->makeOrdinary($thumbnailId, $pdfId, true);
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(sprintf('Rapls PDF Image Creator: image #%d of PDF #%d could not be deleted with the PDF; it was kept as an ordinary image.', $thumbnailId, $pdfId));
                    $result = self::RELEASE_FAILED;
                } elseif ((int) get_post_meta($pdfId, self::THUMBNAIL_META_KEY, true) !== $thumbnailId) {
                    // Refused while a regeneration replaces it: the PDF
                    // already names its new thumbnail, so there is no next
                    // attempt either, and the old one stayed marked and
                    // hidden with nothing naming it. Kept as an ordinary
                    // image; generate() says so (review of 1.4.39).
                    $this->makeOrdinary($thumbnailId, $pdfId);
                    $result = self::RELEASE_FAILED;
                } else {
                    // Not deleted: the PDF keeps its link, so the image is
                    // not left behind with nothing naming it, and the next
                    // attempt finds it again (R56-03).
                    return self::RELEASE_FAILED;
                }
            }
        }

        // Clean up meta
        foreach ([self::THUMBNAIL_META_KEY, '_thumbnail_id'] as $key) {
            if ((int) get_post_meta($pdfId, $key, true) === $thumbnailId) {
                delete_post_meta($pdfId, $key);
            }
        }

        return $result;
    }

    /**
     * Turn a thumbnail of this PDF's into an ordinary image
     *
     * Its two markers go. When the PDF is being deleted, an image attached
     * to it is attached to the PDF's own parent instead, as WordPress does
     * for a deleted post's attachments: "Uploaded to" named an item that no
     * longer existed (review of 1.4.36).
     *
     * The parent is written to the row alone, as core's own
     * wp_delete_post() does for a deleted post's attachments:
     * wp_update_post() ran save_post and edit_attachment for other
     * plugins, inside a deletion, and touched the image's modified date
     * (review after 1.4.36).
     */
    public function makeOrdinary(int $imageId, int $pdfId, bool $pdfGoing = false): void
    {
        global $wpdb;

        delete_post_meta($imageId, '_rapls_pic_is_thumbnail');
        delete_post_meta($imageId, '_rapls_pic_source_pdf');

        if (!$pdfGoing || !is_object($wpdb) || !isset($wpdb->posts) || !method_exists($wpdb, 'update')) {
            return;
        }
        $image = get_post($imageId);
        $pdf = get_post($pdfId);
        if (is_object($image) && (int) ($image->post_parent ?? 0) === $pdfId) {
            $wpdb->update($wpdb->posts, ['post_parent' => is_object($pdf) ? (int) ($pdf->post_parent ?? 0) : 0], ['ID' => $imageId], ['%d'], ['%d']);
            if (function_exists('clean_post_cache')) {
                clean_post_cache($imageId);
            }
        }
    }

    /**
     * Move an image attached to one PDF onto another
     *
     * Only while it is still attached to the PDF that lets go of it, and
     * by the row alone, as makeOrdinary() does.
     */
    private function attachTo(int $imageId, int $fromPdfId, int $toPdfId): void
    {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->posts) || !method_exists($wpdb, 'update')) {
            return;
        }
        $image = get_post($imageId);
        if (is_object($image) && (int) ($image->post_parent ?? 0) === $fromPdfId) {
            $wpdb->update($wpdb->posts, ['post_parent' => $toPdfId], ['ID' => $imageId], ['%d'], ['%d']);
            if (function_exists('clean_post_cache')) {
                clean_post_cache($imageId);
            }
        }
    }

    /**
     * Attach the ordinary images still attached to a PDF that is going to its parent
     *
     * An old thumbnail kept by a regeneration, because a post showed it, is
     * an ordinary image still attached to the PDF; deleting the PDF later
     * left its "Uploaded to" naming an item that was gone (review after
     * 1.4.38). WordPress moves a deleted post's attachments to its parent
     * the same way; it does not for a deleted attachment's. Images that
     * still carry this plugin's marker are not touched: they are a PDF's
     * thumbnail, this one's to delete or another's to keep. One another PDF
     * shares stays attached to this PDF once it is gone, "Uploaded to"
     * naming nothing; nothing reads that, and which PDF it should name
     * instead is that PDF's to say -- left (review of 1.4.38's fixes).
     */
    public function reparentKeptImages(int $pdfId): void
    {
        global $wpdb;

        if ($pdfId < 1 || !is_object($wpdb) || !isset($wpdb->posts, $wpdb->postmeta) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'update')) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_parent = %d AND p.post_type = 'attachment'
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_rapls_pic_is_thumbnail')",
            $pdfId
        ));
        if (!is_array($ids) || '' !== (string) $wpdb->last_error) {
            return;
        }
        $pdf = get_post($pdfId);
        $parent = is_object($pdf) ? (int) ($pdf->post_parent ?? 0) : 0;
        foreach ($ids as $id) {
            $wpdb->update($wpdb->posts, ['post_parent' => $parent], ['ID' => (int) $id], ['%d'], ['%d']);
            if (function_exists('clean_post_cache')) {
                clean_post_cache((int) $id);
            }
        }
    }

    /**
     * Delete an attachment, and say whether it is gone
     *
     * Asked of WordPress afterwards, not read from the return value:
     * pre_delete_attachment may return the post itself -- a WP_Post, truthy
     * -- and nothing is deleted; and an attachment that another request
     * deleted first comes back as null although it is gone (R63-01).
     */
    private function deleteAttachment(int $attachmentId): bool
    {
        wp_delete_attachment($attachmentId, true);

        return null === get_post($attachmentId);
    }

    /**
     * Create WordPress attachment for thumbnail
     *
     * @param int $pdfId Parent PDF attachment ID
     * @param string $filePath Thumbnail file path
     * @param string $filename Thumbnail filename
     * @return int|null Attachment ID or null on failure
     */
    private function createThumbnailAttachment(int $pdfId, string $filePath, string $filename): ?int
    {
        // Get file info
        $fileType = wp_check_filetype($filename);

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $fileType['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $pdfId,
        ];

        // Let go before the insert, not after: core adds the row and then
        // runs add_attachment, save_post and wp_insert_post, where offload
        // and optimiser plugins listen. One that throws or times out there,
        // or in the metadata below, left an attachment in the Media Library
        // whose file shutdown then deleted (review after 1.4.32). An insert
        // that returns no attachment deletes the file itself, below.
        self::holdUntilAdded(null);

        // Insert attachment
        $attachmentId = wp_insert_attachment($attachment, $filePath, $pdfId);

        if (is_wp_error($attachmentId) || !$attachmentId) {
            // No attachment, so the file is nobody's.
            wp_delete_file($filePath);

            return null;
        }

        // Generate attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, $metadata);

        // Mark as PDF thumbnail (for filtering in media library)
        update_post_meta($attachmentId, '_rapls_pic_is_thumbnail', '1');
        update_post_meta($attachmentId, '_rapls_pic_source_pdf', $pdfId);

        // Read back, not taken on the return values -- false also means "the
        // same value". Without the two marks, isOwnThumbnail() says no, the
        // next regeneration cannot let go of this image, and it is left
        // behind for good; without its metadata, the image has no sizes
        // (R56-01). Only that metadata was saved is asked, not what it holds:
        // a filter may add to it. Asked even when none could be generated:
        // an image always has some (its size, its file), and without it the
        // Media Library shows the PDF without this thumbnail
        // (filterAttachmentForJs()) while this said it was made (R57-02).
        $stored = wp_get_attachment_metadata($attachmentId);

        // And the file the attachment names is the file drawn: a filter on
        // update_attached_file, or a failed write, can leave it naming
        // another, and wp_insert_attachment() still returns the ID (R58-02).
        $named = (string) get_attached_file($attachmentId, true);
        $namesFile = '' !== $named && (realpath($named) ?: $named) === (realpath($filePath) ?: $filePath);

        // Unless core put a copy in front of it: past big_image_size_threshold
        // (2560 by default), on an output-format conversion, or for an EXIF
        // rotation, wp_create_image_subsizes() saves "-scaled" (or converted,
        // or "-rotated") beside the file drawn, names that one, and keeps the
        // drawn file's name as the metadata's original_image -- as for any
        // image uploaded. Taken for "names another file", every thumbnail past
        // 2560 pixels a side was thrown away and its -scaled copy left on disk
        // (review of 1.4.39). Worked out here, not by
        // wp_get_original_image_path(), which WordPress 5.0 to 5.2 lack.
        // original_image is a bare name, as core's wp_basename() left it.
        if (!$namesFile && '' !== $named && is_array($stored) && isset($stored['original_image'])
            && is_string($stored['original_image']) && '' !== $stored['original_image']
            && false === strpbrk($stored['original_image'], '/\\')) {
            $original = dirname($named) . '/' . $stored['original_image'];
            $namesFile = (realpath($original) ?: $original) === (realpath($filePath) ?: $filePath);
        }

        if (!$namesFile || !$this->isOwnThumbnail($attachmentId, $pdfId) || !is_array($stored) || [] === $stored) {
            // Deleting an attachment deletes the file it names. Naming another
            // file, it is first pointed back at this one; if it will not be,
            // nothing is deleted -- that file may be someone else's.
            if (!$namesFile) {
                update_attached_file($attachmentId, $filePath);
                $named = (string) get_attached_file($attachmentId, true);

                if ('' === $named || (realpath($named) ?: $named) !== (realpath($filePath) ?: $filePath)) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(sprintf('Rapls PDF Image Creator: attachment #%d was saved naming a different file than the thumbnail drawn for PDF #%d, and could not be corrected; it was left as it is.', $attachmentId, $pdfId));

                    return null;
                }
            }

            // Deleted with its file. Not deleted -- pre_delete_attachment can
            // refuse -- the file stays too: an attachment whose file has gone
            // is worse than an extra image (R58-01); and that is said (R60-01).
            $this->discardNewThumbnail($attachmentId, $pdfId, 'did not come out as drawn');

            return null;
        }

        return $attachmentId;
    }

    /**
     * Check server capabilities
     *
     * @return array<string, mixed>
     */
    public function checkCapabilities(): array
    {
        $capabilities = [
            'engines' => [],
            'available' => false,
            'availability' => $this->getAvailabilityStatus(),
        ];

        foreach ($this->engines as $name => $engine) {
            $capabilities['engines'][$name] = [
                'name' => $engine->getDisplayName(),
                'available' => $engine->isAvailable(),
                'requirements' => $engine->getRequirements(),
            ];

            if ($engine->isAvailable()) {
                $capabilities['available'] = true;
            }
        }

        return $capabilities;
    }
}
