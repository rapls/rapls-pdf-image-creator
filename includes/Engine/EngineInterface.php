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
     * Explain *why* the engine is or is not available
     *
     * isAvailable() collapses several distinct server states into one bool.
     * A site owner needs them apart: a missing extension and a blocked PDF
     * coder require different requests to a hosting provider.
     *
     * @return array{code: string, label: string, summary: string, action: string, detail: string}
     */
    public function getAvailabilityStatus(): array;

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
