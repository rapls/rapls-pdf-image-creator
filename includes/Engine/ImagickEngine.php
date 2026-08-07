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

                $formats = \Imagick::queryFormats('PDF');
                $requirements['pdf_support'] = [
                    'name' => 'PDF Support',
                    'status' => !empty($formats),
                    'message' => !empty($formats)
                        ? __('Available', 'rapls-pdf-image-creator')
                        : __('Not available (PDF delegate may be missing)', 'rapls-pdf-image-creator'),
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

            // Set resolution before reading (important for quality)
            $imagick->setResolution($options['resolution'], $options['resolution']);

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
