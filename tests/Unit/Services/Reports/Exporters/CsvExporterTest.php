<?php

declare(strict_types=1);

use App\Services\Reports\Exporters\CsvExporter;
use App\Services\Reports\ReportBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
|--------------------------------------------------------------------------
| CsvExporter — unit tests
|--------------------------------------------------------------------------
|
| The exporter does not depend on the DB — it consumes a synthetic
| {header, rows, total, truncated, ...} array. These tests pin the wire
| contract (BOM, header row, truncation marker) without touching MySQL.
*/

/**
 * Build a small synthetic export-data payload.
 *
 * @param  list<array<string, string>>  $rows
 * @return array{header: list<string>, rows: Generator, total: int, truncated: bool, type: string, date_from: string, date_to: string}
 */
function csvFixture(array $rows, bool $truncated = false, int $total = 0): array
{
    $header = ['order_id', 'order_public_id', 'total_amount', 'currency'];

    $generator = (function () use ($rows): Generator {
        foreach ($rows as $row) {
            yield $row;
        }
    })();

    return [
        'header' => $header,
        'rows' => $generator,
        'total' => $total > 0 ? $total : count($rows),
        'truncated' => $truncated,
        'type' => 'sales',
        'date_from' => '2026-04-16',
        'date_to' => '2026-05-16',
    ];
}

describe('CsvExporter::export()', function (): void {
    it('returns a StreamedResponse with csv content-type + filename', function (): void {
        $exporter = new CsvExporter;
        $response = $exporter->export(csvFixture([
            ['order_id' => '1', 'order_public_id' => 'p1', 'total_amount' => '100.00', 'currency' => 'AED'],
        ]));

        expect($response)->toBeInstanceOf(StreamedResponse::class);
        $ct = (string) $response->headers->get('Content-Type');
        expect(str_starts_with($ct, 'text/csv'))->toBeTrue();
        $cd = (string) $response->headers->get('Content-Disposition');
        expect($cd)->toContain('attachment');
        expect($cd)->toContain('pwt-sales-2026-04-16-2026-05-16.csv');
    });

    it('prepends the UTF-8 BOM and writes the header row first', function (): void {
        $exporter = new CsvExporter;
        $response = $exporter->export(csvFixture([
            ['order_id' => '42', 'order_public_id' => 'p42', 'total_amount' => '123.45', 'currency' => 'AED'],
        ]));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

        $bodyNoBom = ltrim($body, "\xEF\xBB\xBF");
        $lines = preg_split('/\r?\n/', $bodyNoBom) ?: [];
        expect($lines[0])->toContain('order_id');
        expect($lines[0])->toContain('order_public_id');
        expect($lines[0])->toContain('total_amount');
        expect($lines[0])->toContain('currency');

        // Row 2 is the data row.
        expect($lines[1])->toContain('42');
        expect($lines[1])->toContain('p42');
        expect($lines[1])->toContain('123.45');
    });

    it('respects the header column order even when row keys differ', function (): void {
        $exporter = new CsvExporter;

        // Row keys are deliberately reordered.
        $response = $exporter->export(csvFixture([
            ['currency' => 'AED', 'total_amount' => '99.99', 'order_public_id' => 'pid', 'order_id' => '7'],
        ]));

        ob_start();
        $response->sendContent();
        $body = ltrim((string) ob_get_clean(), "\xEF\xBB\xBF");
        $lines = preg_split('/\r?\n/', $body) ?: [];

        // Data row should match the header order: order_id, order_public_id, total_amount, currency.
        expect($lines[1])->toBe('7,pid,99.99,AED');
    });

    it('appends the _truncated marker + sets the truncated header when truncated=true', function (): void {
        $exporter = new CsvExporter;
        $rows = array_map(
            static fn (int $i): array => [
                'order_id' => (string) $i,
                'order_public_id' => "pid-$i",
                'total_amount' => '10.00',
                'currency' => 'AED',
            ],
            range(1, 3),
        );

        $response = $exporter->export(csvFixture($rows, truncated: true, total: 12345));

        expect($response->headers->get('X-PWT-Export-Truncated'))->toBe('true');
        // Row-count header is capped at EXPORT_ROW_CAP for the X-header.
        expect((int) $response->headers->get('X-PWT-Export-Row-Count'))
            ->toBe(min(12345, ReportBuilder::EXPORT_ROW_CAP));

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        expect($body)->toContain('_truncated');
    });

    it('sets Cache-Control: no-store to protect PII', function (): void {
        $exporter = new CsvExporter;
        $response = $exporter->export(csvFixture([]));

        $cc = (string) $response->headers->get('Cache-Control');
        expect($cc)->toContain('no-store');
    });

    it('streams (generator is consumed lazily — no in-memory rowset)', function (): void {
        $exporter = new CsvExporter;

        $seen = 0;
        $generator = (function () use (&$seen): Generator {
            foreach (range(1, 5) as $i) {
                $seen++;
                yield [
                    'order_id' => (string) $i,
                    'order_public_id' => "p$i",
                    'total_amount' => '1.00',
                    'currency' => 'AED',
                ];
            }
        })();

        $response = $exporter->export([
            'header' => ['order_id', 'order_public_id', 'total_amount', 'currency'],
            'rows' => $generator,
            'total' => 5,
            'truncated' => false,
            'type' => 'sales',
            'date_from' => '2026-04-16',
            'date_to' => '2026-05-16',
        ]);

        // Before sendContent() runs, the generator has not been consumed.
        expect($seen)->toBe(0);

        ob_start();
        $response->sendContent();
        ob_end_clean();

        expect($seen)->toBe(5);
    });
});
