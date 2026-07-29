<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/reports/sales
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 3.1 / § 4.1 / § 4.1.1 /
| § 6.4.
|
| The summary + buckets EXCLUDE cancelled orders; the rows list still
| includes them so admins can audit cancellations within the same window.
| Daily buckets when the window is ≤ 60 days, monthly otherwise.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
    $this->qatar = Country::query()->where('code', 'qa')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function reportsSalesBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{0: User, 1: Address}
 */
function reportsSalesCustomer(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()
        ->forUser($user)
        ->forCountry($country)
        ->create(['is_default' => true]);

    return [$user, $address];
}

function reportsSalesOrder(User $customer, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($customer)
        ->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('GET /api/v1/admin/reports/sales — authorization matrix', function (): void {
    it('returns 200 for super_admin', function (): void {
        [, $bearer] = reportsSalesBearer(UserRole::SuperAdmin->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['filters', 'summary', 'buckets', 'rows'],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
            ]);
    });

    it('returns 200 for operations', function (): void {
        [, $bearer] = reportsSalesBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();
    });

    it('returns 200 for sales', function (): void {
        [, $bearer] = reportsSalesBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();
    });

    it('returns 403 for support (per-type narrowing — admin-reports.md § 6.4)', function (): void {
        // support is allowed reports.view at the catalog level but is
        // narrowed to maintenance + customers by ReportPolicy::view().
        // The named gate (`reports.access`) is distinct from the spatie
        // permission key (`reports.view`) so Gate::before does NOT
        // short-circuit, and the per-type matrix runs to completion.
        [, $bearer] = reportsSalesBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN for a customer token (admin guard rejects)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/reports/sales')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $this->getJson('/api/v1/admin/reports/sales')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/reports/sales — bucket resolution', function (): void {
    it('produces daily buckets when the range is ≤ 60 days', function (): void {
        [, $bearer] = reportsSalesBearer();
        [$customer, $address] = reportsSalesCustomer();

        // Two orders 5 days apart inside a 30-day window.
        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '1000.00',
            'total_amount' => '1050.00',
            'created_at' => now()->subDays(10),
        ]);
        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '500.00',
            'total_amount' => '525.00',
            'created_at' => now()->subDays(5),
        ]);

        $from = now()->subDays(20)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/reports/sales?date_from=$from&date_to=$to")
            ->assertOk();

        expect($response->json('data.filters.bucket'))->toBe('daily');
        $buckets = $response->json('data.buckets');
        expect($buckets)->toBeArray()->not->toBeEmpty();
        // Daily bucket keys look like YYYY-MM-DD (10 chars).
        foreach ($buckets as $bucket) {
            expect(strlen($bucket['bucket']))->toBe(10);
        }
    });

    it('produces monthly buckets when the range > 60 days', function (): void {
        [, $bearer] = reportsSalesBearer();
        [$customer, $address] = reportsSalesCustomer();

        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '1000.00',
            'subtotal_amount' => '950.00',
            'created_at' => now()->subDays(90),
        ]);
        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '500.00',
            'subtotal_amount' => '475.00',
            'created_at' => now()->subDays(30),
        ]);

        $from = now()->subDays(120)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/reports/sales?date_from=$from&date_to=$to")
            ->assertOk();

        expect($response->json('data.filters.bucket'))->toBe('monthly');
        $buckets = $response->json('data.buckets');
        expect($buckets)->toBeArray()->not->toBeEmpty();
        // Monthly bucket keys look like YYYY-MM (7 chars).
        foreach ($buckets as $bucket) {
            expect(strlen($bucket['bucket']))->toBe(7);
        }
    });
});

describe('GET /api/v1/admin/reports/sales — filters', function (): void {
    it('filters by product_id (joins order_items)', function (): void {
        [, $bearer] = reportsSalesBearer();
        [$customer, $address] = reportsSalesCustomer();

        $needle = Product::factory()->create();
        $noise = Product::factory()->create();

        $needleOrder = reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '1500.00',
            'subtotal_amount' => '1500.00',
        ]);
        OrderItem::factory()->forOrder($needleOrder)->forProduct($needle)->withUnitPrice(1500.00)->create();

        $noiseOrder = reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '700.00',
        ]);
        OrderItem::factory()->forOrder($noiseOrder)->forProduct($noise)->withUnitPrice(700.00)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales?product_id='.$needle->id)
            ->assertOk();

        expect($response->json('data.summary.orders_count'))->toBe(1);
        expect($response->json('meta.pagination.total'))->toBe(1);
    });

    it('filters by country (resolved via delivery_address → countries.code)', function (): void {
        [, $bearer] = reportsSalesBearer();

        [$uaeCustomer, $uaeAddress] = reportsSalesCustomer($this->uae);
        reportsSalesOrder($uaeCustomer, $uaeAddress, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '1000.00',
        ]);

        [$qatarCustomer, $qatarAddress] = reportsSalesCustomer($this->qatar);
        reportsSalesOrder($qatarCustomer, $qatarAddress, [
            'status' => OrderStatus::Completed->value,
            'total_amount' => '2000.00',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales?country=ae')
            ->assertOk();

        expect($response->json('data.summary.orders_count'))->toBe(1);
        expect($response->json('meta.pagination.total'))->toBe(1);
    });
});

describe('GET /api/v1/admin/reports/sales — cancelled-order treatment', function (): void {
    it('excludes cancelled orders from summary + buckets but includes them in rows', function (): void {
        [, $bearer] = reportsSalesBearer();
        [$customer, $address] = reportsSalesCustomer();

        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Completed->value,
            'subtotal_amount' => '1000.00',
            'total_amount' => '1050.00',
        ]);
        reportsSalesOrder($customer, $address, [
            'status' => OrderStatus::Cancelled->value,
            'subtotal_amount' => '9999.00',
            'total_amount' => '9999.00',
            'cancelled_at' => now(),
            'cancellation_reason' => 'test',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();

        // Summary counts only the non-cancelled order.
        expect($response->json('data.summary.orders_count'))->toBe(1);
        expect((float) $response->json('data.summary.net_revenue'))->toBe(1050.00);

        // Buckets aggregate excludes the cancelled order.
        $bucketTotals = collect($response->json('data.buckets'))
            ->sum(static fn (array $b): float => (float) $b['net_revenue']);
        expect($bucketTotals)->toBe(1050.00);

        // Rows still include the cancelled order.
        expect($response->json('meta.pagination.total'))->toBe(2);
        $statuses = collect($response->json('data.rows'))->pluck('status')->all();
        expect($statuses)->toContain('cancelled');
        expect($statuses)->toContain('completed');
    });
});

describe('GET /api/v1/admin/reports/sales — pagination + validation', function (): void {
    it('paginates rows at 25 per page by default', function (): void {
        [, $bearer] = reportsSalesBearer();
        [$customer, $address] = reportsSalesCustomer();

        for ($i = 0; $i < 30; $i++) {
            reportsSalesOrder($customer, $address, [
                'status' => OrderStatus::Completed->value,
                'subtotal_amount' => '100.00',
                'total_amount' => '105.00',
                'created_at' => now()->subDays(1),
            ]);
        }

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales')
            ->assertOk();

        expect($response->json('meta.pagination.per_page'))->toBe(25);
        expect($response->json('meta.pagination.total'))->toBe(30);
        expect($response->json('data.rows'))->toHaveCount(25);
    });

    it('returns 422 ERR_VALIDATION when per_page > 100', function (): void {
        [, $bearer] = reportsSalesBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales?per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('returns 422 when date_to is before date_from', function (): void {
        [, $bearer] = reportsSalesBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/sales?date_from=2026-05-01&date_to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_to']]);
    });

    it('returns 422 when the date window exceeds 366 days', function (): void {
        [, $bearer] = reportsSalesBearer();

        // Pick a from-date that is > 366 days before today but still within
        // the 3-year hard floor in the validator.
        $from = now()->subDays(500)->toDateString();
        $to = now()->toDateString();

        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/reports/sales?date_from=$from&date_to=$to")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_to']]);
    });
});
