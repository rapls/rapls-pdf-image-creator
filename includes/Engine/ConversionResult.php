<?php
/**
 * Conversion Result
 *
 * @package PDFImageCreator\Engine
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator\Engine;

/**
 * Represents the result of a PDF to image conversion
 */
final class ConversionResult
{
    /**
     * Whether conversion succeeded
     *
     * @var bool
     */
    private bool $success;

    /**
     * Path to output file (if successful)
     *
     * @var string
     */
    private string $outputPath;

    /**
     * Error message (if failed)
     *
     * @var string|null
     */
    private ?string $error;

    /**
     * Image width
     *
     * @var int
     */
    private int $width;

    /**
     * Image height
     *
     * @var int
     */
    private int $height;

    /**
     * File size in bytes
     *
     * @var int
     */
    private int $fileSize;

    /**
     * Time taken in seconds
     *
     * @var float
     */
    private float $conversionTime;

    /**
     * Constructor
     *
     * @param bool $success Whether conversion succeeded
     * @param string $outputPath Path to output file (if successful)
     * @param string|null $error Error message (if failed)
     * @param int $width Image width
     * @param int $height Image height
     * @param int $fileSize File size in bytes
     * @param float $conversionTime Time taken in seconds
     */
    public function __construct(
        bool $success,
        string $outputPath = '',
        ?string $error = null,
        int $width = 0,
        int $height = 0,
        int $fileSize = 0,
        float $conversionTime = 0.0
    ) {
        $this->success = $success;
        $this->outputPath = $outputPath;
        $this->error = $error;
        $this->width = $width;
        $this->height = $height;
        $this->fileSize = $fileSize;
        $this->conversionTime = $conversionTime;
    }

    /**
     * Create a successful result
     *
     * @param string $outputPath Path to output file
     * @param int $width Image width
     * @param int $height Image height
     * @param int $fileSize File size in bytes
     * @param float $conversionTime Time taken in seconds
     * @return self
     */
    public static function success(
        string $outputPath,
        int $width = 0,
        int $height = 0,
        int $fileSize = 0,
        float $conversionTime = 0.0
    ): self {
        return new self(
            true,
            $outputPath,
            null,
            $width,
            $height,
            $fileSize,
            $conversionTime
        );
    }

    /**
     * Create a failed result
     *
     * @param string $error Error message
     * @return self
     */
    public static function failure(string $error): self
    {
        return new self(false, '', $error);
    }

    /**
     * Check if conversion was successful
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get output file path
     *
     * @return string
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Get error message
     *
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get image width
     *
     * @return int
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Get image height
     *
     * @return int
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Get file size
     *
     * @return int
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * Get conversion time
     *
     * @return float
     */
    public function getConversionTime(): float
    {
        return $this->conversionTime;
    }
}
