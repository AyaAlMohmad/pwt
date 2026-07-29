<?php

declare(strict_types=1);

namespace App\Services\Reports\Exporters;

use InvalidArgumentException;

/**
 * Resolve the right {@see ExporterInterface} for the requested format.
 *
 * The Form Request validates `format` against `in:csv,xlsx` so an invalid
 * value never reaches this factory — the default arm of the match
 * therefore throws a 500-level error (caught by the global handler).
 */
final class ExporterFactory
{
    public function make(string $format): ExporterInterface
    {
        return match ($format) {
            'csv' => new CsvExporter,
            'xlsx' => new XlsxExporter,
            default => throw new InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }
}
