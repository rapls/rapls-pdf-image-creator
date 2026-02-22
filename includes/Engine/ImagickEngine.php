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

            // Handle CMYK colorspace (PDF/X-1:2001 etc.)
            // Must convert BEFORE flattening to avoid black output
            $colorspace = $imagick->getImageColorspace();
            if ($colorspace === \Imagick::COLORSPACE_CMYK) {
                // Transform CMYK to sRGB
                $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }

            // Set background color for transparency
            $bgColor = $this->getBgColor($options['bgcolor']);
            $imagick->setImageBackgroundColor($bgColor);

            // Flatten image (removes transparency)
            $imagick = $imagick->flattenImages();

            // Resize if necessary
            $this->resizeImage($imagick, $options['max_width'], $options['max_height']);

            // Set output format
            $format = strtoupper($options['format']);
            if ($format === 'JPEG' || $format === 'JPG') {
                $imagick->setImageFormat('JPEG');
                $imagick->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $imagick->setImageCompressionQuality($options['quality']);
                // Convert to RGB colorspace for JPEG
                $imagick->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            } elseif ($format === 'PNG') {
                $imagick->setImageFormat('PNG');
                $imagick->setImageCompressionQuality($options['quality']);
            } elseif ($format === 'WEBP') {
                $imagick->setImageFormat('WEBP');
                $imagick->setImageCompressionQuality($options['quality']);
            } else {
                $imagick->setImageFormat('JPEG');
                $imagick->setImageCompressionQuality($options['quality']);
            }

            // Strip metadata
            $imagick->stripImage();

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
            default:
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
