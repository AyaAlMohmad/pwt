<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use App\Services\Reports\Exporters\CsvExporter;
use App\Services\Reports\ReportBuilder;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/reports/{type}/export
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 3.5 / § 3.6 / § 4.5 /
| § 6.8. Streams CSV / XLSX of the chosen report. The throttle limits
| each user to 10 successful requests per minute.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('reports-export');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function reportsExportBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;
    RateLimiter::clear('reports-export:'.$admin->id);

    return [$admin, 'Bearer '.$token];
}

function reportsExportSeedOrders(int $count = 2): void
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    for ($i = 0; $i < $count; $i++) {
        Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '1000.00',
            'total_amount' => '1050.00',
            'currency' => 'AED',
        ])->create();
    }
}

describe('GET /api/v1/admin/reports/{type}/export — CSV', function (): void {
    it('returns 200 with text/csv content-type, UTF-8 BOM, filename header', function (): void {
        [, $bearer] = reportsExportBearer();
        reportsExportSeedOrders(3);

        $response = $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=csv');

        $response->assertOk();

        // Content-Type assertion (charset suffix tolerated).
        $ct = $response->headers->get('Content-Type');
        expect($ct)->not->toBeNull();
        expect(str_starts_with((string) $ct, 'text/csv'))->toBeTrue();

        // Filename header is present.
        $cd = $response->headers->get('Content-Disposition');
        expect($cd)->not->toBeNull();
        expect((string) $cd)->toContain('attachment');
        expect((string) $cd)->toContain('pwt-sales-');
        expect((string) $cd)->toContain('.csv');

        // Body begins with the UTF-8 BOM. Use streamedContent() because the
        // controller returns a StreamedResponse.
        $body = $response->streamedContent();
        expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();

        // The truncation header is present and false on small exports.
        expect($response->headers->get('X-PWT-Export-Truncated'))->toBe('false');
        // PROD-ISSUE-21-2 fix: ReportBuilder.exportData() now wraps the
        // grouped query in a subquery before counting, so the row count
        // reflects the distinct grouped rows the export actually streams.
        expect((int) $response->headers->get('X-PWT-Export-Row-Count'))->toBe(3);

        // No-store cache control protects PII.
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('emits a header row reflecting the report column allowlist', function (): void {
        [, $bearer] = reportsExportBearer();
        reportsExportSeedOrders(1);

        $response = $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=csv');

        $body = $response->streamedContent();
        // Strip BOM, then the first newline-delimited line is the header.
        $body = ltrim($body, "\xEF\xBB\xBF");
        $firstLine = strtok($body, "\n");
        expect($firstLine)->toContain('order_id');
        expect($firstLine)->toContain('order_public_id');
        expect($firstLine)->toContain('total_amount');
    });
});

describe('GET /api/v1/admin/reports/{type}/export — XLSX', function (): void {
    it('returns 200 with the XLSX content-type', function (): void {
        [, $bearer] = reportsExportBearer();
        reportsExportSeedOrders(2);

        $response = $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=xlsx');

        $response->assertOk();

        $ct = $response->headers->get('Content-Type');
        expect($ct)->not->toBeNull();
        expect(str_starts_with((string) $ct, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
            ->toBeTrue();

        $cd = $response->headers->get('Content-Disposition');
        expect((string) $cd)->toContain('.xlsx');
    });

    it('returns 422 when format is missing', function (): void {
        [, $bearer] = reportsExportBearer();

        $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['format']]);
    });

    it('returns 422 when format is invalid', function (): void {
        [, $bearer] = reportsExportBearer();

        $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=pdf')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});

describe('GET /api/v1/admin/reports/{type}/export — authorization', function (): void {
    it('returns 403 for support exporting sales (per-type narrowing — admin-reports.md § 6.4)', function (): void {
        [, $bearer] = reportsExportBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=csv')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 200 for support exporting maintenance', function (): void {
        [, $bearer] = reportsExportBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/maintenance/export?format=csv')
            ->assertOk();
    });

    it('returns 404 for an unknown report type (route constraint)', function (): void {
        [, $bearer] = reportsExportBearer();

        $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/unknown/export?format=csv')
            ->assertStatus(404);
    });

    it('returns 401 without a Bearer token', function (): void {
        $this->getJson('/api/v1/admin/reports/sales/export?format=csv')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/reports/{type}/export — row cap', function (): void {
    it('exposes the X-PWT-Export-Truncated header on every response (false on small sets)', function (): void {
        // End-to-end coverage of the 10,000 row cap is left to the
        // ReportBuilder + CsvExporter unit tests, where the `truncated`
        // flag and the `_truncated` marker row are asserted directly.
        // ReportBuilder is `final`, so we cannot swap the binding for a
        // subclass in this feature test, and seeding 10,001 real orders
        // would balloon test runtime to minutes. The end-to-end happy-
        // path test below confirms the header is always present.
        [, $bearer] = reportsExportBearer();
        reportsExportSeedOrders(2);

        $response = $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=csv');

        $response->assertOk();
        expect($response->headers->get('X-PWT-Export-Truncated'))->toBe('false');
        expect($response->headers->get('X-PWT-Export-Row-Count'))->not->toBeNull();
    });

    it('CsvExporter emits the _truncated marker when ReportBuilder flags truncation', function (): void {
        // Direct exporter test (no DB) — feeds a synthetic truncated
        // payload through CsvExporter and asserts the marker + header.
        // This mirrors the e2e "10,000 rows then 11th truncates"
        // scenario without forcing the feature pipeline to materialise
        // 10,001 rows.
        $exporter = new CsvExporter;

        $generator = (function (): Generator {
            for ($i = 0; $i < 3; $i++) {
                yield ['order_id' => (string) $i, 'order_public_id' => "p$i", 'total_amount' => '10.00', 'currency' => 'AED'];
            }
        })();

        $response = $exporter->export([
            'header' => ['order_id', 'order_public_id', 'total_amount', 'currency'],
            'rows' => $generator,
            'total' => 12500,
            'truncated' => true,
            'type' => 'sales',
            'date_from' => '2026-04-16',
            'date_to' => '2026-05-16',
        ]);

        expect($response->headers->get('X-PWT-Export-Truncated'))->toBe('true');
        expect((int) $response->headers->get('X-PWT-Export-Row-Count'))
            ->toBe(ReportBuilder::EXPORT_ROW_CAP);

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();
        expect($body)->toContain('_truncated');
    });
});

describe('GET /api/v1/admin/reports/{type}/export — rate limiting', function (): void {
    it('returns 429 ERR_RATE_LIMITED on the 11th call within a minute', function (): void {
        [$admin, $bearer] = reportsExportBearer();
        reportsExportSeedOrders(1);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->withHeader('Authorization', $bearer)
                ->get('/api/v1/admin/reports/sales/export?format=csv');
            $response->assertOk();
        }

        $blocked = $this->withHeader('Authorization', $bearer)
            ->get('/api/v1/admin/reports/sales/export?format=csv');
        $blocked->assertStatus(429)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });
});
