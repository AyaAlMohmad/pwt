<?php

declare(strict_types=1);

use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/machines + GET /api/v1/admin/machines/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.1 / § 4.1 /
| § 4.2 / § 5.2 / § 5.3 / § 6.1.
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
function adminMachinesBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{0: User, 1: Address, 2: Product}
 */
function adminMachinesCustomerStack(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create();
    $product = Product::factory()->create();

    return [$user, $address, $product];
}

function adminMachine(User $user, Address $address, Product $product, array $overrides = []): Machine
{
    return Machine::factory()
        ->forUser($user)
        ->forProduct($product)
        ->state(array_merge([
            'address_id' => $address->id,
            'country_code' => 'ae',
        ], $overrides))
        ->create();
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/machines — list
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/machines — list', function (): void {
    it('returns 200 with paginated list for super_admin', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::SuperAdmin->value);
        [$customer, $address, $product] = adminMachinesCustomerStack();
        adminMachine($customer, $address, $product);
        adminMachine($customer, $address, $product);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    '*' => [
                        'id', 'public_id', 'serial_number',
                        'product' => ['id', 'code'],
                        'customer' => ['id', 'name', 'email', 'phone'],
                        'address',
                        'country_code', 'term', 'installed_at',
                        'next_maintenance_due_at', 'filter_change_interval_months',
                        'is_active', 'source', 'order_id',
                        'needs_maintenance', 'open_requests_count', 'is_trashed',
                        'created_at',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('returns 200 for operations role', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::Operations->value);
        [$customer, $address, $product] = adminMachinesCustomerStack();
        adminMachine($customer, $address, $product);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 403 ERR_FORBIDDEN for sales role (no machines.view)', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN for support role (no machines.view)', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN for a customer token (admin guard)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/machines')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        $this->getJson('/api/v1/admin/machines')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('filters search by serial number (LIKE)', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $needle = adminMachine($customer, $address, $product, ['serial_number' => 'NEEDLE-001234']);
        adminMachine($customer, $address, $product, ['serial_number' => 'OTHER-9876']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?search=NEEDLE');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $needle->id);
    });

    it('filters search via product code (LIKE) — bonus coverage of the orWhereHas branch', function (): void {
        [, $bearer] = adminMachinesBearer();
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $productMatch = Product::factory()->create(['code' => 'NEEDLECODE-X1']);
        $productOther = Product::factory()->create();
        adminMachine($customer, $address, $productMatch);
        adminMachine($customer, $address, $productOther);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?search=NEEDLECODE')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by country_code=ae', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$uaeCust, $uaeAddr, $uaeProd] = adminMachinesCustomerStack($this->uae);
        adminMachine($uaeCust, $uaeAddr, $uaeProd, ['country_code' => 'ae']);
        [$qatarCust, $qatarAddr, $qatarProd] = adminMachinesCustomerStack($this->qatar);
        adminMachine($qatarCust, $qatarAddr, $qatarProd, ['country_code' => 'qa']);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?country_code=ae')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.country_code', 'ae');
    });

    it('filters by is_active=false', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        adminMachine($customer, $address, $product, ['is_active' => true]);
        $inactive = adminMachine($customer, $address, $product, ['is_active' => false]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?is_active=0')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $inactive->id);
    });

    it('filters by needs_maintenance=true (defaults within_days=30)', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $dueSoon = adminMachine($customer, $address, $product, [
            'next_maintenance_due_at' => now()->addDays(7)->toDateString(),
        ]);
        adminMachine($customer, $address, $product, [
            'next_maintenance_due_at' => now()->addDays(90)->toDateString(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?needs_maintenance=1');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $dueSoon->id);
    });

    it('filters by source=imported and term=rent', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        adminMachine($customer, $address, $product, [
            'source' => MachineSource::Imported->value,
            'term' => ProductTerm::Rent->value,
        ]);
        adminMachine($customer, $address, $product, [
            'source' => MachineSource::Created->value,
            'term' => ProductTerm::Buy->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?source=imported&term=rent')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.source', 'imported')
            ->assertJsonPath('data.0.term', 'rent');
    });

    it('honours per_page pagination meta and accepts custom sort', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        for ($i = 0; $i < 5; $i++) {
            adminMachine($customer, $address, $product);
        }

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?per_page=2&sort=installed_at&order=asc');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.last_page', 3);
    });

    it('excludes soft-deleted by default; super_admin may opt in with include_trashed=1', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::SuperAdmin->value);
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $live = adminMachine($customer, $address, $product);
        $dead = adminMachine($customer, $address, $product);
        $dead->delete();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?include_trashed=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    });

    it('returns 403 when a non-super_admin passes include_trashed=1', function (): void {
        [, $bearer] = adminMachinesBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?include_trashed=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 422 for an out-of-range within_days', function (): void {
        [, $bearer] = adminMachinesBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines?within_days=999')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['within_days']]);
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/machines/{id} — show
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/machines/{id} — show', function (): void {
    it('returns 200 with the full admin detail shape', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $machine = adminMachine($customer, $address, $product, [
            'notes' => 'Office unit, business hours only',
            'location_label' => 'HQ kitchen',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/machines/{$machine->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'serial_number',
                    'product' => ['id', 'code'],
                    'customer' => ['id', 'name', 'email', 'phone'],
                    'address',
                    'country_code', 'term', 'installed_at',
                    'next_maintenance_due_at', 'filter_change_interval_months',
                    'is_active', 'source', 'order_id',
                    'needs_maintenance', 'open_requests_count',
                    'is_trashed', 'created_at',
                    'notes', 'location_label',
                    'latest_completed_log_at', 'total_logs_count',
                    'maintenance_logs', 'maintenance_requests',
                    'deleted_at', 'order',
                ],
            ])
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.notes', 'Office unit, business hours only')
            ->assertJsonPath('data.location_label', 'HQ kitchen')
            ->assertJsonPath('data.is_trashed', false);
    });

    it('returns 404 ERR_NOT_FOUND for a non-existent id', function (): void {
        [, $bearer] = adminMachinesBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/9999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 403 ERR_FORBIDDEN for sales/support', function (): void {
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $machine = adminMachine($customer, $address, $product);

        foreach ([UserRole::Sales->value, UserRole::Support->value] as $role) {
            [, $bearer] = adminMachinesBearer($role);
            $this->withHeader('Authorization', $bearer)
                ->getJson("/api/v1/admin/machines/{$machine->id}")
                ->assertStatus(403)
                ->assertJsonPath('code', 'ERR_FORBIDDEN');
        }
    });

    it('returns 401 without a Bearer token', function (): void {
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $machine = adminMachine($customer, $address, $product);

        $this->getJson("/api/v1/admin/machines/{$machine->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('includes trashed machines on the show endpoint (audit)', function (): void {
        [, $bearer] = adminMachinesBearer();
        [$customer, $address, $product] = adminMachinesCustomerStack();
        $machine = adminMachine($customer, $address, $product);
        $machine->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/machines/{$machine->id}")
            ->assertOk();

        expect($response->json('data.id'))->toBe($machine->id);
        expect($response->json('data.is_trashed'))->toBeTrue();
    });
});
