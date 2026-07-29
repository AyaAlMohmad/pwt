<?php

declare(strict_types=1);

use App\Services\Reports\Exporters\XlsxExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| XlsxExporter — unit tests
|--------------------------------------------------------------------------
|
| Asserts that the generated XLSX is a valid file and that the header
| row + data rows land in the expected cells. The exporter does not
| depend on the DB.
*/

/**
 * @param  list<array<string, string>>  $rows
 * @return array{header: list<string>, rows: Generator, total: int, truncated: bool, type: string, date_from: string, date_to: string}
 */
function xlsxFixture(array $rows, bool $truncated = false, int $total = 0): array
{
    $generator = (function () use ($rows): Generator {
        foreach ($rows as $row) {
            yield $row;
        }
    })();

    return [
        'header' => ['id', 'name', 'amount'],
        'rows' => $generator,
        'total' => $total > 0 ? $total : count($rows),
        'truncated' => $truncated,
        'type' => 'sales',
        'date_from' => '2026-04-16',
        'date_to' => '2026-05-16',
    ];
}

function xlsxRenderBody(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

describe('XlsxExporter::export()', function (): void {
    it('returns a StreamedResponse with the spreadsheetml content-type', function (): void {
        $exporter = new XlsxExporter;
        $response = $exporter->export(xlsxFixture([]));

        expect($response)->toBeInstanceOf(StreamedResponse::class);
        $ct = (string) $response->headers->get('Content-Type');
        expect(str_starts_with($ct, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue();

        $cd = (string) $response->headers->get('Content-Disposition');
        expect($cd)->toContain('pwt-sales-2026-04-16-2026-05-16.xlsx');
    });

    it('writes a header row in row 1 and data rows starting row 2', function (): void {
        $exporter = new XlsxExporter;
        $response = $exporter->export(xlsxFixture([
            ['id' => '1', 'name' => 'Alpha', 'amount' => '10.00'],
            ['id' => '2', 'name' => 'Beta', 'amount' => '20.00'],
        ]));

        $body = xlsxRenderBody($response);

        // Materialise to a temp file then read back with PhpSpreadsheet.
        $tmp = tempnam(sys_get_temp_dir(), 'pwt-xlsx-');
        expect($tmp)->not->toBeFalse();
        // PhpSpreadsheet's reader requires the .xlsx suffix to detect the
        // correct format reliably.
        $tmpXlsx = $tmp.'.xlsx';
        rename($tmp, $tmpXlsx);
        file_put_contents($tmpXlsx, $body);

        $spreadsheet = IOFactory::load($tmpXlsx);
        $sheet = $spreadsheet->getActiveSheet();

        expect($sheet->getCell('A1')->getValue())->toBe('id');
        expect($sheet->getCell('B1')->getValue())->toBe('name');
        expect($sheet->getCell('C1')->getValue())->toBe('amount');

        expect((string) $sheet->getCell('A2')->getValue())->toBe('1');
        expect($sheet->getCell('B2')->getValue())->toBe('Alpha');
        expect($sheet->getCell('B3')->getValue())->toBe('Beta');

        @unlink($tmpXlsx);
    });

    it('sets the X-PWT-Export-Truncated header to true when truncated', function (): void {
        $exporter = new XlsxExporter;
        $response = $exporter->export(xlsxFixture([
            ['id' => '1', 'name' => 'A', 'amount' => '1.00'],
        ], truncated: true, total: 99999));

        expect($response->headers->get('X-PWT-Export-Truncated'))->toBe('true');
    });

    it('sets Cache-Control: no-store', function (): void {
        $exporter = new XlsxExporter;
        $response = $exporter->export(xlsxFixture([]));

        $cc = (string) $response->headers->get('Cache-Control');
        expect($cc)->toContain('no-store');
    });

    it('emits a sheet titled after the report type', function (): void {
        $exporter = new XlsxExporter;
        $response = $exporter->export(xlsxFixture([
            ['id' => '1', 'name' => 'A', 'amount' => '1.00'],
        ]));

        $body = xlsxRenderBody($response);
        $tmp = tempnam(sys_get_temp_dir(), 'pwt-xlsx-');
        $tmpXlsx = $tmp.'.xlsx';
        rename($tmp, $tmpXlsx);
        file_put_contents($tmpXlsx, $body);

        $spreadsheet = IOFactory::load($tmpXlsx);
        expect($spreadsheet->getActiveSheet()->getTitle())->toBe('Sales');

        @unlink($tmpXlsx);
    });
});
