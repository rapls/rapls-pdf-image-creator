<?php
/**
 * Engine Interface
 *
 * @package PDFImageCreator\Engine
 */

declare(strict_types=1);

namespace Rapls\PDFImageCreator\Engine;

/**
 * Interface for PDF to image conversion engines
 */
interface EngineInterface
{
    /**
     * Get engine name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get display name for UI
     *
     * @return string
     */
    public function getDisplayName(): string;

    /**
     * Check if engine is available on this server
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get requirements/status information
     *
     * @return array<string, mixed>
     */
    public function getRequirements(): array;

    /**
     * Convert PDF page to image
     *
     * @param string $pdfPath Path to PDF file
     * @param string $outputPath Output image path
     * @param array<string, mixed> $options Conversion options
     * @return ConversionResult
     */
    public function convert(string $pdfPath, string $outputPath, array $options = []): ConversionResult;
}
