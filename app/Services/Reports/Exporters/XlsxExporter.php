<?php

declare(strict_types=1);

namespace App\Services\Reports\Exporters;

use App\Services\Reports\ReportBuilder;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLSX streaming exporter via PhpSpreadsheet.
 *
 * PhpSpreadsheet does NOT natively stream — it builds the spreadsheet in
 * memory before writing. We use `$writer->save('php://output')` inside a
 * StreamedResponse callback so the HTTP-layer buffer is the only memory
 * pressure point, and the 10 000 row cap keeps the in-memory worksheet
 * size bounded (~5–10 MB worst case).
 *
 * See docs/architecture/admin-reports.md § 4.5.2.
 */
final class XlsxExporter implements ExporterInterface
{
    public function export(array $data): StreamedResponse
    {
        $filename = sprintf(
            'pwt-%s-%s-%s.xlsx',
            $data['type'],
            $data['date_from'],
            $data['date_to'],
        );

        $response = new StreamedResponse(static function () use ($data): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(ucfirst($data['type']));

            // Header row. PhpSpreadsheet 5.x dropped
            // setCellValueByColumnAndRow — use the modern [col, row] array
            // form on setCellValue() instead.
            foreach ($data['header'] as $index => $columnName) {
                $sheet->setCellValue([$index + 1, 1], $columnName);
            }
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
            $sheet->freezePane('A2');

            $rowNumber = 2;
            $rowsWritten = 0;
            foreach ($data['rows'] as $row) {
                $columnIndex = 1;
                foreach ($data['header'] as $columnName) {
                    $value = $row[$columnName] ?? '';
                    $sheet->setCellValue([$columnIndex, $rowNumber], $value);
                    $columnIndex++;
                }
                $rowNumber++;
                $rowsWritten++;
            }

            if ($data['truncated']) {
                $sheet->setCellValue(
                    [1, $rowNumber + 1],
                    sprintf(
                        'Export truncated at %d rows. Narrow filters to retrieve the remaining %d rows.',
                        ReportBuilder::EXPORT_ROW_CAP,
                        max(0, $data['total'] - $rowsWritten),
                    )
                );
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('X-PWT-Export-Truncated', $data['truncated'] ? 'true' : 'false');
        $response->headers->set('X-PWT-Export-Row-Count', (string) min($data['total'], ReportBuilder::EXPORT_ROW_CAP));

        return $response;
    }
}
