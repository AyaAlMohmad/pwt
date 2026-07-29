<?php

declare(strict_types=1);

namespace App\Services\Reports\Exporters;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contract implemented by every report exporter (CSV, XLSX).
 *
 * Implementations MUST stream output (write to `php://output` from inside
 * a {@see StreamedResponse} callback) rather than building the full
 * payload in memory. The {@see ExporterFactory} picks the implementation
 * based on the `format` query param.
 *
 * See docs/architecture/admin-reports.md § 4.5.
 */
interface ExporterInterface
{
    /**
     * Build the streamed download response.
     *
     * @param  array{header: list<string>, rows: \Generator, total: int, truncated: bool, type: string, date_from: string, date_to: string}  $data
     */
    public function export(array $data): StreamedResponse;
}
