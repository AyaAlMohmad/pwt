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
| GET /api/v1/admin/reports/customers
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 3.4 / § 4.4 / § 6.4.
| All four admin roles — including support — get 200 here.
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
function reportsCustomersBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{0: User, 1: Address}
 */
function reportsCustomerInCountry(Country $country, array $userOverrides = []): array
{
    $user = User::factory()->customer()->state($userOverrides)->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create(['is_default' => true]);

    return [$user, $address];
}

describe('GET /api/v1/admin/reports/customers — authorization', function (): void {
    it('returns 200 for super_admin', function (): void {
        [, $bearer] = reportsCustomersBearer(UserRole::SuperAdmin->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'filters',
                    'summary' => [
                        'customers_count', 'active_count',
                        'with_orders_count', 'with_machines_count',
                    ],
                    'buckets', 'rows',
                ],
                'meta' => ['pagination'],
            ]);
    });

    it('returns 200 for operations', function (): void {
        [, $bearer] = reportsCustomersBearer(UserRole::Operations->value);
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers')
            ->assertOk();
    });

    it('returns 200 for sales', function (): void {
        [, $bearer] = reportsCustomersBearer(UserRole::Sales->value);
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers')
            ->assertOk();
    });

    it('returns 200 for support (INCLUDED on customers reports)', function (): void {
        [, $bearer] = reportsCustomersBearer(UserRole::Support->value);
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers')
            ->assertOk();
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/reports/customers')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/reports/customers')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/reports/customers — filters', function (): void {
    it('filters by country (resolved via default address)', function (): void {
        [, $bearer] = reportsCustomersBearer();

        reportsCustomerInCountry($this->uae);
        reportsCustomerInCountry($this->uae);
        reportsCustomerInCountry($this->qatar);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers?country=ae')
            ->assertOk();

        expect($response->json('data.summary.customers_count'))->toBe(2);
        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('counts with_orders_count correctly (excluding cancelled)', function (): void {
        [, $bearer] = reportsCustomersBearer();

        [$buyer, $buyerAddress] = reportsCustomerInCountry($this->uae);
        Order::factory()->forUser($buyer)->forAddress($buyerAddress)->state([
            'status' => OrderStatus::Completed->value,
            'total_amount' => '500.00',
        ])->create();

        // Customer with only a cancelled order — should not count.
        [$cancelledCustomer, $cancelledAddress] = reportsCustomerInCountry($this->uae);
        Order::factory()->forUser($cancelledCustomer)->forAddress($cancelledAddress)->state([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => 'test',
            'total_amount' => '500.00',
        ])->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/customers')
            ->assertOk();

        expect($response->json('data.summary.with_orders_count'))->toBe(1);
    });

    it('filters by registration date range (users.created_at)', function (): void {
        [, $bearer] = reportsCustomersBearer();

        $old = User::factory()->customer()->create(['created_at' => now()->subDays(20)]);
        Address::factory()->forUser($old)->forCountry($this->uae)->create(['is_default' => true]);

        $recent = User::factory()->customer()->create(['created_at' => now()->subDay()]);
        Address::factory()->forUser($recent)->forCountry($this->uae)->create(['is_default' => true]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/reports/customers?date_from=$from&date_to=$to")
            ->assertOk();

        expect($response->json('data.summary.customers_count'))->toBe(1);
        expect($response->json('meta.pagination.total'))->toBe(1);
        expect($response->json('data.rows.0.id'))->toBe($recent->id);
    });

});
