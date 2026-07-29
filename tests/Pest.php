<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Pest\Expectation;
use Tests\MySqlTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| `TestCase` instance. By default, that class is `Tests\TestCase`.
| Feature tests run against a fresh, transactional database via the
| `RefreshDatabase` trait so suites are deterministic.
*/

// Catalog tests run against MySQL (`pwt_testing`) because the catalog
// service uses MySQL-only `JSON_EXTRACT` / `JSON_UNQUOTE` in its raw
// queries. CLAUDE.md § 10 explicitly allows the dedicated
// `pwt_testing` database for MySQL-specific integration tests. The
// `MySqlTestCase` subclass flips the connection BEFORE
// `RefreshDatabase` inspects `database.default`.
//
// Because Pest forbids overriding a folder's test case after it has
// been registered, we list every non-catalog `Feature` subdirectory
// explicitly here and reserve the catalog dirs for `MySqlTestCase`.
uses(TestCase::class, RefreshDatabase::class)->in(
    'Feature/Api/V1/Addresses',
    'Feature/Api/V1/Admin/Auth',
    'Feature/Api/V1/Auth',
    'Feature/Api/V1/Contact',
    'Feature/Api/V1/Files',
    'Feature/Api/V1/Reference',
    'Feature/Api/V1/User',
    'Feature/HealthTest.php',
    'Feature/Middleware',
    'Feature/Seeders/ReferenceSeedersTest.php',
);
// Phase 7 — Promotions module. The customer-facing endpoints and the
// `promotions_count` retroactive wire on the catalog detail share the
// same translatable-JSON read paths as catalog, so we run them on the
// MySQL `pwt_testing` pipeline for parity (and to keep
// `ProductsPromoCountTest` — which lives under Catalog and reuses the
// catalog seeders + JSON_EXTRACT — on the same connection as the rest
// of catalog).
uses(MySqlTestCase::class)->in(
    'Feature/Api/V1/Catalog',
    'Feature/Api/V1/Promotions',
    'Feature/Api/V1/Orders',
    'Feature/Api/V1/Payments',
    'Feature/Api/V1/Me',
    'Feature/Api/V1/Maintenance',
    'Feature/Api/V1/Notifications',
    'Feature/Api/V1/Support',
    'Feature/Listeners',
    'Feature/Seeders/CatalogSeedersTest.php',
    'Feature/Seeders/MachinesFromExcelSeederTest.php',
    'Feature/Seeders/NotificationTemplatesSeederTest.php',
    'Feature/Api/V1/Admin/Users',
    'Feature/Api/V1/Admin/Catalog',
    'Feature/Api/V1/Admin/Content',
    'Feature/Api/V1/Admin/Orders',
    'Feature/Api/V1/Admin/Machines',
    'Feature/Api/V1/Admin/Maintenance',
    'Feature/Api/V1/Admin/Support',
    'Feature/Api/V1/Admin/Reports',
    'Feature/Api/V1/Admin/AuditLogs',
    'Feature/Api/V1/Admin/Templates',
    'Feature/Api/V1/Admin/Dashboard',
    'Unit/Services/Admin',
    'Unit/Services/Reports/ReportBuilderTest.php',
    'Unit/Services/Catalog',
    'Unit/Services/Promotions',
    'Unit/Services/Orders',
    'Unit/Services/Payments',
    'Unit/Services/Maintenance',
    'Unit/Services/Notifications',
    'Unit/Services/Support',
    'Unit/Models/ProductTest.php',
    'Unit/Models/OrderTest.php',
    'Unit/Models/PaymentTest.php',
    'Unit/Models/SupportTicketTest.php',
);
uses(TestCase::class)->in(
    'Unit/Jobs',
    'Unit/Models/ContactLeadTest.php',
    'Unit/Models/FileTest.php',
    'Unit/Policies',
    'Unit/Rules',
    'Unit/Services/Addresses',
    'Unit/Services/Auth',
    'Unit/Services/Contact',
    'Unit/Services/Files',
    'Unit/Services/Reference',
    'Unit/Services/Reports/Exporters',
);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Add custom expectations or shared assertions here as the suite grows.
*/

expect()->extend('toBeSuccessEnvelope', function (): Expectation {
    /** @var array<string, mixed> $value */
    $value = $this->value;

    expect($value)->toBeArray()
        ->and($value)->toHaveKeys(['success', 'message', 'data'])
        ->and($value['success'])->toBeTrue();

    return $this;
});

expect()->extend('toBeErrorEnvelope', function (?string $code = null): Expectation {
    /** @var array<string, mixed> $value */
    $value = $this->value;

    expect($value)->toBeArray()
        ->and($value)->toHaveKeys(['success', 'message'])
        ->and($value['success'])->toBeFalse();

    if ($code !== null) {
        expect($value['code'] ?? null)->toBe($code);
    }

    return $this;
});

/*
|--------------------------------------------------------------------------
| Shared test helpers
|--------------------------------------------------------------------------
|
| Each call resets the in-memory stub SMS driver and clears the named
| rate limiter buckets so tests are isolated.
*/

function resetAuthTestState(): void
{
    StubSmsDriver::flush();

    foreach (
        [
            'otp-request',
            'otp-verify',
            'auth-strict',
            'forgot-password',
            'api',
        ] as $limiter
    ) {
        foreach (
            [
                '+971501234567',
                '+971509999999',
                'user@example.com',
                '127.0.0.1',
                '127.0.0.1:+971501234567',
                '127.0.0.1:user@example.com',
                '127.0.0.1:admin@pwt.local',
                '127.0.0.1:ops@pwt.local',
                '127.0.0.1:sales@pwt.local',
                '127.0.0.1:support@pwt.local',
                '127.0.0.1:customer@pwt.local',
                '127.0.0.1:inactive@pwt.local',
                '127.0.0.1:nobody@pwt.local',
                '127.0.0.1:ratelimit@pwt.local',
            ] as $key
        ) {
            RateLimiter::clear($limiter.':'.$key);
        }
    }
}
