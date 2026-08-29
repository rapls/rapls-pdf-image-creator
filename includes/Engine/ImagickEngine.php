<?php
/**
 * Imagick Engine
 *
 * @package PDFImageCreator\Engine
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator\Engine;

/**
 * PDF to image conversion using PHP Imagick extension
 */
final class ImagickEngine implements EngineInterface
{
    /**
     * Option holding what the last conversion actually did, for the Status tab.
     */
    public const DIAGNOSTICS_OPTION = 'rapls_pic_color_diagnostics';

    /**
     * Colour profile resolver
     */
    private ColorProfile $colorProfile;

    /**
     * Constructor
     *
     * @param ColorProfile|null $colorProfile Profile resolver, for testing.
     */
    public function __construct(?ColorProfile $colorProfile = null)
    {
        $this->colorProfile = $colorProfile ?? new ColorProfile();
    }

    /**
     * Get the colour profile resolver
     */
    public function getColorProfile(): ColorProfile
    {
        return $this->colorProfile;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'imagick';
    }

    /**
     * {@inheritdoc}
     */
    public function getDisplayName(): string
    {
        return 'Imagick (ImageMagick)';
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if (!extension_loaded('imagick') || !class_exists('\Imagick')) {
            return false;
        }

        // Check if PDF is in the supported formats
        try {
            $formats = \Imagick::queryFormats('PDF');
            return !empty($formats);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Transient caching the policy.xml lookup
     */
    private const POLICY_TRANSIENT = 'rapls_pic_pdf_policy';

    /**
     * {@inheritdoc}
     *
     * Nothing here may throw: this runs on every admin page load through the
     * notice, and on the Site Health screen.
     */
    public function getAvailabilityStatus(): array
    {
        if (!extension_loaded('imagick') || !class_exists('\Imagick')) {
            return $this->statusFor('no_extension');
        }

        try {
            $formats = \Imagick::queryFormats('PDF');
        } catch (\Exception $e) {
            return $this->statusFor('error', $e->getMessage());
        }

        if (!empty($formats)) {
            return $this->statusFor('ok');
        }

        // ImageMagick is here but will not touch a PDF. Two different server
        // problems land on the same empty result, and they need different
        // requests to the host, so try to tell them apart.
        $policyFile = $this->findPdfPolicyBlock();

        if (null !== $policyFile) {
            return $this->statusFor(
                'pdf_blocked_by_policy',
                /* translators: %s: absolute path to the policy.xml file */
                sprintf(__('Policy file: %s', 'rapls-pdf-image-creator'), $policyFile)
            );
        }

        return $this->statusFor('pdf_unsupported');
    }

    /**
     * Build the status array for one code
     *
     * Every branch of getAvailabilityStatus() comes through here, so the
     * shape cannot drift between them and the wording stays in one place.
     *
     * @param string $code   One of ok, no_extension, pdf_blocked_by_policy,
     *                       pdf_unsupported, error.
     * @param string $detail Extra machine-ish detail, already translated.
     * @return array{code: string, label: string, summary: string, action: string, detail: string}
     */
    private function statusFor(string $code, string $detail = ''): array
    {
        switch ($code) {
            case 'ok':
                $label = __('Ready', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed and PDF rendering is permitted.', 'rapls-pdf-image-creator');
                $action = '';
                break;

            case 'no_extension':
                $label = __('Imagick extension not installed', 'rapls-pdf-image-creator');
                $summary = __('The Imagick PHP extension is not loaded, so no PDF can be rendered.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to install and enable the Imagick PHP extension (the ImageMagick binding for PHP).', 'rapls-pdf-image-creator');
                break;

            case 'pdf_blocked_by_policy':
                $label = __('PDF blocked by ImageMagick policy', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed, but its security policy forbids reading PDF files. This is a server setting, not a missing component.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to allow the PDF coder in policy.xml. The rule currently denies it, and it needs read rights.', 'rapls-pdf-image-creator');
                break;

            case 'pdf_unsupported':
                $label = __('PDF support missing', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick is installed, but it reports no PDF support. Usually the PDF delegate is absent from the build; a security policy elsewhere on the server can also cause this.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to enable PDF support in ImageMagick, and to confirm that policy.xml does not deny the PDF coder.', 'rapls-pdf-image-creator');
                break;

            default:
                $code = 'error';
                $label = __('Unable to check', 'rapls-pdf-image-creator');
                $summary = __('ImageMagick could not be asked which formats it supports.', 'rapls-pdf-image-creator');
                $action = __('Ask your hosting provider to check the ImageMagick installation.', 'rapls-pdf-image-creator');
                break;
        }

        return [
            'code' => $code,
            'label' => $label,
            'summary' => $summary,
            'action' => $action,
            'detail' => $detail,
        ];
    }

    /**
     * Find a policy.xml that denies the PDF coder
     *
     * Read-only, and deliberately so: the plugin may not run a process, so
     * `identify -list policy` is not an option. Parsing the file ourselves is
     * the only way to separate "policy says no" from "delegate is missing",
     * and that distinction changes what the site owner has to ask for.
     *
     * @return string|null Path of the offending file, or null if none found.
     */
    private function findPdfPolicyBlock(): ?string
    {
        $cached = get_transient(self::POLICY_TRANSIENT);
        if (is_array($cached) && array_key_exists('file', $cached)) {
            return is_string($cached['file']) ? $cached['file'] : null;
        }

        $found = null;

        foreach ($this->getPolicyFileCandidates() as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $contents = @file_get_contents($file);
            if (false === $contents || '' === $contents) {
                continue;
            }

            if ($this->policyDeniesPdf($contents)) {
                $found = $file;
                break;
            }
        }

        set_transient(self::POLICY_TRANSIENT, ['file' => $found], DAY_IN_SECONDS);

        return $found;
    }

    /**
     * Candidate policy.xml paths, most authoritative first
     *
     * @return array<int, string>
     */
    private function getPolicyFileCandidates(): array
    {
        $dirs = [];

        // Runtime overrides win over anything compiled in, and ImageMagick
        // honours them ahead of its own configure path. Verified: with
        // MAGICK_CONFIGURE_PATH pointing at a policy.xml that denies the PDF
        // coder, queryFormats('PDF') comes back empty while
        // getConfigureOptions() still reports the build directory -- so
        // consulting only the latter would miss the file actually in force.
        $env = getenv('MAGICK_CONFIGURE_PATH');
        if (is_string($env) && '' !== $env) {
            $dirs = array_merge($dirs, explode(':', $env));
        }

        $home = getenv('MAGICK_HOME');
        if (is_string($home) && '' !== $home) {
            $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-7';
            $dirs[] = rtrim($home, '/') . '/etc/ImageMagick-6';
            $dirs[] = rtrim($home, '/') . '/config-Q16';
        }

        // ImageMagick tells us where it looks, when the build supports it.
        if (method_exists('\Imagick', 'getConfigureOptions')) {
            try {
                $options = \Imagick::getConfigureOptions('CONFIGURE_PATH');
                if (is_array($options)) {
                    foreach ($options as $value) {
                        if (is_string($value) && '' !== $value) {
                            $dirs = array_merge($dirs, explode(':', $value));
                        }
                    }
                }
            } catch (\Exception $e) {
                // Older builds throw rather than return an empty set.
                $dirs = [];
            }
        }

        // The usual distribution locations, for builds that report nothing.
        $dirs = array_merge($dirs, [
            '/etc/ImageMagick-7',
            '/etc/ImageMagick-6',
            '/etc/ImageMagick',
            '/usr/local/etc/ImageMagick-7',
            '/usr/local/etc/ImageMagick-6',
            '/opt/homebrew/etc/ImageMagick-7',
        ]);

        $files = [];
        foreach ($dirs as $dir) {
            $dir = rtrim(trim((string) $dir), '/');
            if ('' === $dir) {
                continue;
            }
            $files[] = $dir . '/policy.xml';
        }

        /**
         * Filter the policy.xml paths searched
         *
         * Builds in unusual locations, and the test suite, need to point this
         * somewhere else.
         *
         * @param array<int, string> $files Candidate paths, most authoritative first.
         */
        $files = apply_filters('rapls_pdf_image_creator_policy_paths', array_values(array_unique($files)));

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    /**
     * Does this policy.xml text deny reading PDFs?
     *
     * Matched with a regex rather than an XML parser: the file is small, the
     * shape is fixed, and a malformed policy.xml must not turn into a fatal.
     *
     * @param string $xml Raw file contents.
     */
    private function policyDeniesPdf(string $xml): bool
    {
        if (!preg_match_all('/<policy\b[^>]*>/i', $xml, $matches)) {
            return false;
        }

        foreach ($matches[0] as $tag) {
            if (!preg_match('/\bdomain\s*=\s*"([^"]*)"/i', $tag, $domain)) {
                continue;
            }
            if ('coder' !== strtolower(trim($domain[1]))) {
                continue;
            }

            if (!preg_match('/\brights\s*=\s*"([^"]*)"/i', $tag, $rights)) {
                continue;
            }
            $granted = strtolower(trim($rights[1]));
            if ('none' !== $granted && false !== strpos($granted, 'read')) {
                continue;
            }

            if (!preg_match('/\bpattern\s*=\s*"([^"]*)"/i', $tag, $pattern)) {
                continue;
            }

            if ($this->patternCoversPdf(trim($pattern[1]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a policy pattern cover the PDF coder?
     *
     * Patterns seen in the wild: PDF, PDF*, {PS,PS2,PS3,EPS,PDF,XPS}, *.
     *
     * @param string $pattern Raw pattern attribute.
     */
    private function patternCoversPdf(string $pattern): bool
    {
        if ('' === $pattern) {
            return false;
        }

        if ('*' === $pattern) {
            return true;
        }

        $items = [$pattern];
        if ('{' === $pattern[0] && '}' === substr($pattern, -1)) {
            $items = explode(',', substr($pattern, 1, -1));
        }

        foreach ($items as $item) {
            $item = strtoupper(trim($item));
            if ('PDF' === $item || 'PDF*' === $item) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequirements(): array
    {
        $requirements = [
            'extension' => [
                'name' => 'Imagick Extension',
                'status' => extension_loaded('imagick'),
                'message' => extension_loaded('imagick')
                    ? __('Installed', 'rapls-pdf-image-creator')
                    : __('Not installed', 'rapls-pdf-image-creator'),
            ],
        ];

        if (extension_loaded('imagick') && class_exists('\Imagick')) {
            try {
                $version = \Imagick::getVersion();
                $requirements['version'] = [
                    'name' => 'ImageMagick Version',
                    'status' => true,
                    'message' => $version['versionString'] ?? __('Unknown', 'rapls-pdf-image-creator'),
                ];

                // Report the reason, not just the bool: "policy forbids it"
                // and "the build has no PDF delegate" send the site owner to
                // their host with different requests.
                $availability = $this->getAvailabilityStatus();
                $requirements['pdf_support'] = [
                    'name' => 'PDF Support',
                    'status' => 'ok' === $availability['code'],
                    'message' => 'ok' === $availability['code']
                        ? __('Available', 'rapls-pdf-image-creator')
                        : $availability['label'],
                    'detail' => trim($availability['summary'] . ' ' . $availability['detail']),
                ];

                $requirements['color_management'] = $this->getColorManagementStatus();

                $cmykPdf = $this->getCmykPdfStatus();
                if (null !== $cmykPdf) {
                    $requirements['cmyk_pdf'] = $cmykPdf;
                }
            } catch (\Exception $e) {
                $requirements['error'] = [
                    'name' => 'Error',
                    'status' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $requirements;
    }

    /**
     * {@inheritdoc}
     */
    public function convert(string $pdfPath, string $outputPath, array $options = []): ConversionResult
    {
        $startTime = microtime(true);

        // Default options
        $defaults = [
            'page' => 0,
            'max_width' => 1024,
            'max_height' => 1024,
            'quality' => 90,
            'format' => 'jpeg',
            'bgcolor' => 'white',
            'resolution' => 150,
        ];
        $options = array_merge($defaults, $options);

        // Validate PDF file
        if (!file_exists($pdfPath)) {
            return ConversionResult::failure(
                __('PDF file not found.', 'rapls-pdf-image-creator')
            );
        }

        if (!is_readable($pdfPath)) {
            return ConversionResult::failure(
                __('PDF file is not readable.', 'rapls-pdf-image-creator')
            );
        }

        try {
            $imagick = new \Imagick();

            // Set resolution before reading (important for quality). This runs
            // through a filter, so a caller can hand back 0 or a negative --
            // either makes Imagick render nothing. Fall back to the default.
            $resolution = (int) $options['resolution'];
            if ($resolution < 1) {
                $resolution = $defaults['resolution'];
            }
            $imagick->setResolution($resolution, $resolution);

            // Read specific page from PDF
            $page = max(0, (int) $options['page']);
            $imagick->readImage($pdfPath . '[' . $page . ']');

            $sourceColorspace = $imagick->getImageColorspace();

            // Colour conversion runs before any resizing: downsampling can
            // discard the embedded profile the conversion depends on.
            $colorMode = $this->colorProfile->convertToSrgb($imagick);
            $this->recordDiagnostics($sourceColorspace, $colorMode);

            $format = $this->normalizeFormat((string) $options['format']);

            // Transparent regions come back from the PDF delegate as alpha, and
            // flattening them without an explicit background is what produced
            // the all-black thumbnails. JPEG has no alpha at all, so a
            // transparent background there can only mean white.
            $bgColorName = $this->resolveBackground((string) $options['bgcolor'], $format);
            $keepAlpha = 'transparent' === $bgColorName;

            $imagick->setImageBackgroundColor($this->getBgColor($bgColorName));
            $imagick = $this->flatten($imagick);

            if (!$keepAlpha) {
                $this->removeAlphaChannel($imagick);
            }

            // Resize if necessary
            $this->resizeImage($imagick, $options['max_width'], $options['max_height']);

            // Set output format
            $imagick->setImageFormat($format);
            if ($format === 'JPEG') {
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
            }
            $imagick->setImageCompressionQuality($options['quality']);

            // Strip metadata
            $imagick->stripImage();

            // stripImage() also drops the sRGB profile the ICC conversion
            // wrote, so put it back on colour-managed output.
            if (ColorProfile::MODE_ICC === $colorMode) {
                $this->colorProfile->attachSrgb($imagick);
            }

            // Get dimensions before writing
            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            // Ensure output directory exists
            $outputDir = dirname($outputPath);
            if (!is_dir($outputDir)) {
                if (!wp_mkdir_p($outputDir)) {
                    return ConversionResult::failure(
                        __('Failed to create output directory.', 'rapls-pdf-image-creator')
                    );
                }
            }

            // Write image
            $imagick->writeImage($outputPath);

            // Get file size
            $fileSize = filesize($outputPath) ?: 0;

            // Clean up
            $imagick->clear();
            $imagick->destroy();

            $conversionTime = microtime(true) - $startTime;

            /**
             * Filter the Imagick result before returning
             *
             * @param ConversionResult $result The conversion result
             * @param string $pdfPath Original PDF path
             * @param array $options Conversion options
             */
            $result = ConversionResult::success(
                $outputPath,
                $width,
                $height,
                $fileSize,
                $conversionTime
            );

            return apply_filters('rapls_pdf_image_creator_imagick_result', $result, $pdfPath, $options);

        } catch (\ImagickException $e) {
            return ConversionResult::failure(
                sprintf(
                    /* translators: %s: error message */
                    __('Imagick error: %s', 'rapls-pdf-image-creator'),
                    $e->getMessage()
                )
            );
        } catch (\Exception $e) {
            return ConversionResult::failure($e->getMessage());
        }
    }

    /**
     * Warn about CMYK PDFs on ImageMagick 6
     *
     * ImageMagick 6 renders a PDF it judges to be CMYK through its ps:cmyk
     * delegate, which asks Ghostscript for the bmpsep8 device. That writes a
     * separation BMP, and ImageMagick's own BMP reader cannot decode it: the
     * read either throws or yields an empty raster, so the thumbnail comes out
     * blank white. ImageMagick 7 uses pamcmyk32 instead and is unaffected.
     *
     * Nothing in the Imagick API overrides the delegate choice — the device is
     * picked from the PDF's own content — so this reports the problem rather
     * than working around it.
     *
     * @return array{name: string, status: bool, message: string, detail: string}|null
     *         Null when the running ImageMagick is not affected.
     */
    private function getCmykPdfStatus(): ?array
    {
        $major = $this->getImageMagickMajorVersion();

        if (null === $major || $major >= 7) {
            return null;
        }

        return [
            'name' => __('CMYK PDF Rendering', 'rapls-pdf-image-creator'),
            'status' => false,
            'message' => __('ImageMagick 6 — CMYK PDFs may produce a blank image', 'rapls-pdf-image-creator'),
            'detail' => __('RGB PDFs are not affected.', 'rapls-pdf-image-creator'),
        ];
    }

    /**
     * Get the major version of the running ImageMagick
     *
     * @return int|null Null when the version cannot be determined
     */
    private function getImageMagickMajorVersion(): ?int
    {
        try {
            $version = \Imagick::getVersion();
        } catch (\Exception $e) {
            return null;
        }

        $versionString = is_array($version) ? ($version['versionString'] ?? '') : '';

        if (!is_string($versionString) || !preg_match('/ImageMagick\s+(\d+)\./', $versionString, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Build the colour management row for the Status tab
     *
     * @return array{name: string, status: bool, message: string, detail: string}
     */
    private function getColorManagementStatus(): array
    {
        $status = $this->colorProfile->getStatus();

        if (ColorProfile::MODE_NAIVE === $status['mode']) {
            return [
                'name' => __('Color Management', 'rapls-pdf-image-creator'),
                'status' => false,
                'message' => __('Disabled by filter — using simple conversion', 'rapls-pdf-image-creator'),
                'detail' => '',
            ];
        }

        if ($status['managed']) {
            return [
                'name' => __('Color Management', 'rapls-pdf-image-creator'),
                'status' => true,
                'message' => __('Active (ICC)', 'rapls-pdf-image-creator'),
                'detail' => sprintf(
                    /* translators: 1: CMYK profile filename, 2: sRGB profile filename */
                    __('Converting %1$s to %2$s', 'rapls-pdf-image-creator'),
                    basename((string) $status['cmyk']),
                    basename((string) $status['srgb'])
                ),
            ];
        }

        $missing = null === $status['cmyk']
            ? __('No CMYK profile found on this server.', 'rapls-pdf-image-creator')
            : __('No sRGB profile found on this server.', 'rapls-pdf-image-creator');

        return [
            'name' => __('Color Management', 'rapls-pdf-image-creator'),
            'status' => false,
            'message' => __('Inactive — using simple conversion', 'rapls-pdf-image-creator'),
            'detail' => $missing,
        ];
    }

    /**
     * Normalize an output format to an ImageMagick format name
     *
     * @param string $format Configured format
     * @return string 'JPEG', 'PNG' or 'WEBP'
     */
    private function normalizeFormat(string $format): string
    {
        switch (strtoupper($format)) {
            case 'PNG':
                return 'PNG';
            case 'WEBP':
                return 'WEBP';
            default:
                return 'JPEG';
        }
    }

    /**
     * Decide what to flatten transparency onto
     *
     * @param string $bgColor Configured background
     * @param string $format Normalized output format
     * @return string Background colour name
     */
    private function resolveBackground(string $bgColor, string $format): string
    {
        $bgColor = strtolower($bgColor);

        // JPEG cannot store alpha. Flattening onto "transparent" leaves the
        // encoder to pick, and it picks black.
        if ('transparent' === $bgColor && 'JPEG' === $format) {
            $bgColor = 'white';
        }

        /**
         * Filter the background transparent regions are flattened onto.
         *
         * @param string $bgColor 'white', 'black' or 'transparent'.
         * @param string $format  'JPEG', 'PNG' or 'WEBP'.
         */
        $bgColor = apply_filters('rapls_pdf_image_creator_flatten_background', $bgColor, $format);

        // Colour names and hex are case-insensitive to ImageMagick, so
        // normalizing here keeps the transparency check below simple.
        return is_string($bgColor) && '' !== $bgColor ? strtolower($bgColor) : 'white';
    }

    /**
     * Composite the page onto its background colour
     *
     * @param \Imagick $imagick Imagick instance. Released when replaced.
     * @return \Imagick The flattened image, which may be a new instance
     */
    private function flatten(\Imagick $imagick): \Imagick
    {
        // mergeImageLayers() returns a new instance, so the original has to be
        // released explicitly or it leaks for the rest of the request.
        if (method_exists($imagick, 'mergeImageLayers')) {
            $flattened = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $imagick->clear();
            return $flattened;
        }

        // flattenImages() is deprecated and unreliable under ImageMagick 7,
        // but it is all that is left on very old builds.
        $flattened = $imagick->flattenImages();
        $imagick->clear();

        return $flattened;
    }

    /**
     * Drop the alpha channel after flattening
     *
     * @param \Imagick $imagick Imagick instance
     */
    private function removeAlphaChannel(\Imagick $imagick): void
    {
        try {
            // ALPHACHANNEL_REMOVE composites against the background colour and
            // needs Imagick 3.4.4. Older builds only have OPAQUE, which makes
            // every pixel fully opaque instead.
            if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            } else {
                $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            }
        } catch (\Exception $e) {
            // The image is already flattened; an opaque alpha channel is only
            // a few wasted bytes.
        }
    }

    /**
     * Record what the last conversion did, for the Status tab
     *
     * @param int $colorspace Colorspace reported right after readImage()
     * @param string $mode ColorProfile::MODE_* value
     */
    private function recordDiagnostics(int $colorspace, string $mode): void
    {
        $stored = get_option(self::DIAGNOSTICS_OPTION);

        if (
            is_array($stored)
            && ($stored['colorspace'] ?? null) === $colorspace
            && ($stored['mode'] ?? null) === $mode
        ) {
            return;
        }

        update_option(
            self::DIAGNOSTICS_OPTION,
            [
                'colorspace' => $colorspace,
                'mode' => $mode,
                'time' => time(),
            ],
            false
        );
    }

    /**
     * Get background color object
     *
     * @param string $color Color name or hex
     * @return \ImagickPixel
     */
    private function getBgColor(string $color): \ImagickPixel
    {
        $colorLower = strtolower($color);
        switch ($colorLower) {
            case 'black':
                return new \ImagickPixel('black');
            case 'transparent':
                return new \ImagickPixel('transparent');
            case 'white':
                return new \ImagickPixel('white');
        }

        // The flatten_background filter can supply any colour ImageMagick
        // parses, e.g. '#f5f5f5'. An unparseable value throws.
        try {
            return new \ImagickPixel($color);
        } catch (\Exception $e) {
            return new \ImagickPixel('white');
        }
    }

    /**
     * Resize image maintaining aspect ratio
     *
     * @param \Imagick $imagick Imagick instance
     * @param int $maxWidth Maximum width
     * @param int $maxHeight Maximum height
     */
    private function resizeImage(\Imagick $imagick, int $maxWidth, int $maxHeight): void
    {
        $width = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        if ($width <= $maxWidth && $height <= $maxHeight) {
            return;
        }

        // Calculate new dimensions
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int) round($width * $ratio);
        $newHeight = (int) round($height * $ratio);

        // Use Lanczos filter for high quality
        $imagick->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
    }
}
