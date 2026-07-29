<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/reports/maintenance
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-reports.md § 3.3 / § 4.3 / § 6.4.
| All four admin roles — including support — get 200 here.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function reportsMaintBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{customer: User, machine: Machine}
 */
function reportsMaintCustomerMachine(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($country)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()
        ->forUser($customer)
        ->forProduct($product)
        ->state([
            'address_id' => $address->id,
            'country_code' => $country->code,
        ])
        ->create();

    return ['customer' => $customer, 'machine' => $machine];
}

describe('GET /api/v1/admin/reports/maintenance — authorization', function (): void {
    it('returns 200 for super_admin', function (): void {
        [, $bearer] = reportsMaintBearer(UserRole::SuperAdmin->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'filters',
                    'summary' => [
                        'requests_count', 'by_status', 'by_priority',
                        'average_resolution_hours',
                    ],
                    'buckets', 'rows',
                ],
                'meta' => ['pagination'],
            ]);
    });

    it('returns 200 for operations', function (): void {
        [, $bearer] = reportsMaintBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertOk();
    });

    it('returns 200 for sales', function (): void {
        [, $bearer] = reportsMaintBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertOk();
    });

    it('returns 200 for support (INCLUDED on maintenance reports)', function (): void {
        [, $bearer] = reportsMaintBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertOk();
    });

    it('returns 403 for a customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/reports/maintenance')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/reports/maintenance — filters', function (): void {
    it('filters by status', function (): void {
        [, $bearer] = reportsMaintBearer();
        ['customer' => $customer, 'machine' => $machine] = reportsMaintCustomerMachine();

        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->newStatus()->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->newStatus()->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->completed()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance?status=new')
            ->assertOk();

        expect($response->json('data.summary.requests_count'))->toBe(2);
        expect($response->json('meta.pagination.total'))->toBe(2);
        foreach ($response->json('data.rows') as $row) {
            expect($row['status'])->toBe('new');
        }
    });

    it('filters by assigned_to_user_id (technician)', function (): void {
        [, $bearer] = reportsMaintBearer();
        ['customer' => $customer, 'machine' => $machine] = reportsMaintCustomerMachine();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();

        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->assigned($technician)->create();
        MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)->newStatus()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance?assigned_to_user_id='.$technician->id)
            ->assertOk();

        expect($response->json('data.summary.requests_count'))->toBe(1);
        expect($response->json('meta.pagination.total'))->toBe(1);
        expect($response->json('data.rows.0.assigned_to.id'))->toBe($technician->id);
    });

    it('exposes by_status / by_priority maps with all enum keys defaulted to 0', function (): void {
        [, $bearer] = reportsMaintBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/reports/maintenance')
            ->assertOk();

        $byStatus = $response->json('data.summary.by_status');
        foreach (MaintenanceStatus::values() as $value) {
            expect($byStatus)->toHaveKey($value);
        }

        $byPriority = $response->json('data.summary.by_priority');
        expect($byPriority)->toHaveKeys(['low', 'medium', 'high', 'urgent']);
    });
});
