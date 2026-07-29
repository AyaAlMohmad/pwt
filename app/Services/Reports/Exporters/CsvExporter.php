<?php

declare(strict_types=1);

namespace App\Services\Reports\Exporters;

use App\Services\Reports\ReportBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV streaming exporter.
 *
 * - UTF-8 with BOM (Excel-compatibility).
 * - LF line endings via `fputcsv` default + `\n` for trailing markers.
 * - RFC 4180 quoting via `fputcsv` defaults.
 * - Rows pulled from the {@see ReportBuilder}
 *   generator so memory stays bounded.
 *
 * See docs/architecture/admin-reports.md § 4.5.1.
 */
final class CsvExporter implements ExporterInterface
{
    public function export(array $data): StreamedResponse
    {
        $filename = sprintf(
            'pwt-%s-%s-%s.csv',
            $data['type'],
            $data['date_from'],
            $data['date_to'],
        );

        $response = new StreamedResponse(static function () use ($data): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $data['header']);

            $rowCount = 0;
            foreach ($data['rows'] as $row) {
                $ordered = [];
                foreach ($data['header'] as $column) {
                    $ordered[] = $row[$column] ?? '';
                }
                fputcsv($handle, $ordered);
                $rowCount++;
            }

            if ($data['truncated']) {
                fwrite($handle, '_truncated,"Export truncated at '.ReportBuilder::EXPORT_ROW_CAP.' rows. Narrow filters to retrieve the remaining '.max(0, $data['total'] - $rowCount)." rows.\"\n");
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-PWT-Export-Truncated', $data['truncated'] ? 'true' : 'false');
        $response->headers->set('X-PWT-Export-Row-Count', (string) min($data['total'], ReportBuilder::EXPORT_ROW_CAP));

        return $response;
    }
}
