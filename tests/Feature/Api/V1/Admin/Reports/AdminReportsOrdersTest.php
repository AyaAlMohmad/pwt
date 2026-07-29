<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/reports/orders
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 3.2 / § 4.2 / § 6.4.
| support → 403; super_admin / operations / sales → 200.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function reportsOrdersBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{0: User, 1: Address}
 */
function reportsOrdersCustomer(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create([
        'is_default' => true,
    ]);

    return [$user, $address];
}

function reportsOrdersOrder(User $customer, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($customer)
        ->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('GET /api/v1/admin/reports/orders — authorization', function (): void {
    it('returns 200 for super_admin', function (): void {
        [, $bearer] = reportsOrdersBearer(UserRole::SuperAdmin->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'filters',
                    'summary' => ['orders_count', 'total_gross', 'currency', 'by_status'],
                    'buckets',
                    'rows',
                ],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);
    });

    it('returns 200 for operations', function (): void {
        [, $bearer] = reportsOrdersBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders')
            ->assertOk();
    });

    it('returns 200 for sales', function (): void {
        [, $bearer] = reportsOrdersBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders')
            ->assertOk();
    });

    it('returns 403 for support (per-type narrowing — admin-reports.md § 6.4)', function (): void {
        [, $bearer] = reportsOrdersBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/reports/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/reports/orders')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/reports/orders — filters', function (): void {
    it('filters by status', function (): void {
        [, $bearer] = reportsOrdersBearer();
        [$customer, $address] = reportsOrdersCustomer();

        reportsOrdersOrder($customer, $address, ['status' => OrderStatus::Pending->value]);
        reportsOrdersOrder($customer, $address, ['status' => OrderStatus::Pending->value]);
        reportsOrdersOrder($customer, $address, [
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders?status=pending')
            ->assertOk();

        expect($response->json('data.summary.orders_count'))->toBe(2);
        expect($response->json('data.summary.by_status.pending'))->toBe(2);
        expect($response->json('meta.pagination.total'))->toBe(2);
        foreach ($response->json('data.rows') as $row) {
            expect($row['status'])->toBe('pending');
        }
    });

    it('filters by date_from / date_to', function (): void {
        [, $bearer] = reportsOrdersBearer();
        [$customer, $address] = reportsOrdersCustomer();

        reportsOrdersOrder($customer, $address, ['created_at' => now()->subDays(20)]);
        $inWindow = reportsOrdersOrder($customer, $address, ['created_at' => now()->subDay()]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/reports/orders?date_from=$from&date_to=$to")
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1);
        expect($response->json('data.rows.0.order_id'))->toBe($inWindow->id);
    });

    it('returns by_status with every OrderStatus value defaulted to 0', function (): void {
        [, $bearer] = reportsOrdersBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders')
            ->assertOk();

        $byStatus = $response->json('data.summary.by_status');
        foreach (OrderStatus::values() as $statusValue) {
            expect($byStatus)->toHaveKey($statusValue);
        }
    });

    it('returns 422 when per_page > 100', function (): void {
        [, $bearer] = reportsOrdersBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/orders?per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});
