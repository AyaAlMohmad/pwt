<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/dashboard
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 4.4 / § 6.2.3 / § 6.5.
|
| Open to ANY admin role (super_admin, operations, sales, support).
| Customer → 403 via the `admin` middleware.
| Cache TTL is config-driven; in tests we explicitly set it per-case so
| both the "live compute" and "second call returns cached" paths are
| covered.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    // Always start from a clean cache so the first call exercises the
    // compute path. Tests that need to assert a cache hit re-enable the
    // TTL explicitly.
    Cache::flush();
    config(['dashboard.cache_ttl' => 0]);
});

/**
 * @return array{0: User, 1: string}
 */
function dashBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function dashCustomerWithAddress(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create(['is_default' => true]);

    return [$user, $address];
}

describe('GET /api/v1/admin/dashboard — authorization matrix', function (): void {
    it('returns 200 with all 5 KPI blocks for super_admin', function (): void {
        [, $bearer] = dashBearer(UserRole::SuperAdmin->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'orders' => [
                        'pending',
                        'confirmed',
                        'scheduled',
                        'out_for_delivery',
                        'installed',
                        'completed',
                        'cancelled',
                        'total_this_month',
                    ],
                    'revenue' => ['this_month', 'currency'],
                    'maintenance' => ['pending_requests', 'due_this_month'],
                    'customers' => ['new_this_month', 'total_active'],
                    'machines' => ['total_active', 'due_maintenance_30d'],
                ],
                'meta' => ['generated_at', 'cache_ttl_seconds', 'cached'],
            ]);
    });

    it('returns 200 for operations', function (): void {
        [, $bearer] = dashBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['orders', 'revenue', 'maintenance', 'customers', 'machines']]);
    });

    it('returns 200 for sales', function (): void {
        [, $bearer] = dashBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    });

    it('returns 200 for support', function (): void {
        [, $bearer] = dashBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/dashboard — KPI numbers match seeded fixtures', function (): void {
    it('counts orders per status, totals this-month volume, and excludes cancelled from revenue', function (): void {
        [, $bearer] = dashBearer();
        [$customer, $address] = dashCustomerWithAddress();

        // 2 pending, 1 confirmed, 1 cancelled — all in the current month.
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Pending->value, 'total_amount' => '100.00'])
            ->create();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Pending->value, 'total_amount' => '50.00'])
            ->create();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Confirmed->value, 'total_amount' => '300.00', 'confirmed_at' => now()])
            ->create();
        Order::factory()->forUser($customer)->forAddress($address)
            ->state(['status' => OrderStatus::Cancelled->value, 'total_amount' => '999.99', 'cancelled_at' => now()])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($response->json('data.orders.pending'))->toBe(2)
            ->and($response->json('data.orders.confirmed'))->toBe(1)
            ->and($response->json('data.orders.cancelled'))->toBe(1)
            ->and($response->json('data.orders.total_this_month'))->toBe(4)
            // revenue this month = 100 + 50 + 300, cancelled (999.99) excluded.
            ->and($response->json('data.revenue.this_month'))->toBe('450.00')
            ->and($response->json('data.revenue.currency'))->toBe('AED');
    });

    it('counts pending maintenance requests (new / assigned / in_progress) and excludes terminals', function (): void {
        [, $bearer] = dashBearer();
        [$customer, $address] = dashCustomerWithAddress();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])
            ->create();

        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::InProgress->value])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state([
                'status' => MaintenanceStatus::Completed->value,
                'resolution_notes' => 'done',
                'closed_at' => now(),
            ])->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
            ->state([
                'status' => MaintenanceStatus::Cancelled->value,
                'cancellation_reason' => 'n/a',
                'closed_at' => now(),
            ])->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($response->json('data.maintenance.pending_requests'))->toBe(3);
    });

    it('counts customers (only role=customer, active, not soft-deleted)', function (): void {
        [, $bearer] = dashBearer();

        // 3 customers this month (counts both `is_active=true` AND
        // any soft-deleted ones excluded).
        $active = User::factory()->customer()->create(['is_active' => true]);
        User::factory()->customer()->create(['is_active' => true]);
        User::factory()->customer()->create(['is_active' => true]);
        $inactive = User::factory()->customer()->inactive()->create();
        $trashed = User::factory()->customer()->create();
        $trashed->delete();

        // Admins MUST NOT appear in the customer counts.
        User::factory()->admin(UserRole::Operations->value)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        // `total_active` counts the 3 `is_active=true` customers.
        // `new_this_month` counts non-soft-deleted customer rows created
        // in the current month: 3 active + 1 inactive = 4 (the trashed
        // customer is excluded by the `whereNull('deleted_at')` filter
        // in the service).
        expect($response->json('data.customers.total_active'))->toBe(3)
            ->and($response->json('data.customers.new_this_month'))->toBe(4);

        unset($active, $inactive);
    });

    it('counts machines (active + due-in-30-days window)', function (): void {
        [, $bearer] = dashBearer();
        [$customer, $address] = dashCustomerWithAddress();
        $product = Product::factory()->create();

        // 2 active + 1 inactive + 1 trashed. Only 1 due in next 30 days.
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'is_active' => true,
                'next_maintenance_due_at' => now()->addDays(7)->toDateString(),
            ])->create();
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'is_active' => true,
                'next_maintenance_due_at' => now()->addDays(90)->toDateString(),
            ])->create();
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id, 'is_active' => false])->create();
        $trashed = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();
        $trashed->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($response->json('data.machines.total_active'))->toBe(2)
            ->and($response->json('data.machines.due_maintenance_30d'))->toBe(1);
    });
});

describe('GET /api/v1/admin/dashboard — caching', function (): void {
    it('serves the second call from cache when TTL > 0 (no DB requery)', function (): void {
        [, $bearer] = dashBearer();
        config(['dashboard.cache_ttl' => 60]);
        Cache::flush();

        // Prime the cache with the first call.
        $first = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($first->json('meta.cached'))->toBeFalse();

        // Track DB queries on the second call — the dashboard tables
        // (orders / maintenance_requests / machines / users) MUST NOT
        // be re-hit because the snapshot comes straight from cache.
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $second = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($second->json('meta.cached'))->toBeTrue()
            ->and($second->json('data'))->toBe($first->json('data'));

        $aggregationHits = array_filter($queries, static function (string $sql): bool {
            $needle = strtolower($sql);

            // The token lookup (`personal_access_tokens`) and the user
            // hydration always run; the dashboard-aggregation queries
            // (orders/machines/maintenance_requests/model_has_roles)
            // must NOT.
            return str_contains($needle, 'from `orders`')
                || str_contains($needle, 'from `maintenance_requests`')
                || str_contains($needle, 'from `machines`')
                || str_contains($needle, 'from `model_has_roles`');
        });

        expect($aggregationHits)->toBeEmpty();
    });

    it('always recomputes when TTL=0', function (): void {
        [, $bearer] = dashBearer();
        config(['dashboard.cache_ttl' => 0]);

        $first = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
        $second = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        expect($first->json('meta.cached'))->toBeFalse()
            ->and($second->json('meta.cached'))->toBeFalse();
    });

    it('exposes cache_ttl_seconds in the meta block', function (): void {
        [, $bearer] = dashBearer();
        config(['dashboard.cache_ttl' => 60]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('meta.cache_ttl_seconds', 60);
    });
});
