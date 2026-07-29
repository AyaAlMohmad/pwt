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
| GET /api/v1/admin/maintenance-requests + GET .../{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.5 / § 4.3 /
| § 4.4 / § 5.6 / § 6.1. comments.xlsx row 18.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function adminMaintBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{customer: User, machine: Machine}
 */
function adminMaintMachine(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($country)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
        'address_id' => $address->id,
        'country_code' => $country->code,
    ])->create();

    return ['customer' => $customer, 'machine' => $machine];
}

function adminMaintRequest(User $customer, Machine $machine, array $overrides = []): MaintenanceRequest
{
    return MaintenanceRequest::factory()
        ->forUser($customer)
        ->forMachine($machine)
        ->state($overrides)
        ->create();
}

/*
|--------------------------------------------------------------------------
| GET list
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/maintenance-requests — list', function (): void {
    it('returns 200 with paginated list for super_admin', function (): void {
        [, $bearer] = adminMaintBearer(UserRole::SuperAdmin->value);
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        adminMaintRequest($customer, $machine);
        adminMaintRequest($customer, $machine);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    '*' => [
                        'id', 'public_id', 'issue_type', 'description_excerpt',
                        'status', 'priority', 'preferred_date',
                        'customer' => ['id', 'name', 'email', 'phone'],
                        'machine' => ['id', 'serial_number'],
                        'assigned_to', 'country_code', 'created_at',
                        'closed_at', 'is_trashed',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('returns 200 for operations role', function (): void {
        [, $bearer] = adminMaintBearer(UserRole::Operations->value);
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        adminMaintRequest($customer, $machine);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 200 for support role (read-only)', function (): void {
        [, $bearer] = adminMaintBearer(UserRole::Support->value);
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        adminMaintRequest($customer, $machine);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 403 ERR_FORBIDDEN for sales role', function (): void {
        [, $bearer] = adminMaintBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/maintenance-requests')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/maintenance-requests')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('filters by status', function (): void {
        [, $bearer] = adminMaintBearer();
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        adminMaintRequest($customer, $machine, ['status' => MaintenanceStatus::New->value]);
        adminMaintRequest($customer, $machine, ['status' => MaintenanceStatus::Completed->value, 'closed_at' => now()]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?status=new')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'new');
    });

    it('filters by customer_id', function (): void {
        [, $bearer] = adminMaintBearer();
        ['customer' => $a, 'machine' => $machineA] = adminMaintMachine();
        ['customer' => $b, 'machine' => $machineB] = adminMaintMachine();
        adminMaintRequest($a, $machineA);
        adminMaintRequest($b, $machineB);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?customer_id='.$a->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.customer.id', $a->id);
    });

    it('filters by machine_id', function (): void {
        [, $bearer] = adminMaintBearer();
        ['customer' => $customer, 'machine' => $machineA] = adminMaintMachine();
        $address = Address::factory()->forUser($customer)->forCountry($this->uae)->create();
        $machineB = Machine::factory()->forUser($customer)->state(['address_id' => $address->id])->create();
        adminMaintRequest($customer, $machineA);
        adminMaintRequest($customer, $machineB);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?machine_id='.$machineA->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.machine.id', $machineA->id);
    });

    it('filters by assigned_to_user_id=0 (unassigned only)', function (): void {
        [, $bearer] = adminMaintBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();

        adminMaintRequest($customer, $machine, ['assigned_to_user_id' => null]);
        adminMaintRequest($customer, $machine, [
            'assigned_to_user_id' => $technician->id,
            'status' => MaintenanceStatus::Assigned->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?assigned_to_user_id=0')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by assigned_to_user_id to a specific technician', function (): void {
        [, $bearer] = adminMaintBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();

        adminMaintRequest($customer, $machine, [
            'assigned_to_user_id' => $technician->id,
            'status' => MaintenanceStatus::Assigned->value,
        ]);
        adminMaintRequest($customer, $machine, ['assigned_to_user_id' => null]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?assigned_to_user_id='.$technician->id)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 422 for an out-of-range per_page', function (): void {
        [, $bearer] = adminMaintBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests?per_page=500')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('returns Arabic system messages with Accept-Language: ar', function (): void {
        [, $bearer] = adminMaintBearer();
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        adminMaintRequest($customer, $machine);

        $response = $this->withHeader('Authorization', $bearer)
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/admin/maintenance-requests')
            ->assertOk();

        // System message is translated to Arabic.
        expect($response->json('message'))->toBe('تم استرجاع طلبات الصيانة.');
    });
});

/*
|--------------------------------------------------------------------------
| GET show
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/maintenance-requests/{id} — show', function (): void {
    it('returns 200 with full admin detail incl. internal_notes', function (): void {
        [, $bearer] = adminMaintBearer();
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        $req = adminMaintRequest($customer, $machine, [
            'internal_notes' => 'Confidential admin note',
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/maintenance-requests/{$req->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'issue_type', 'description_excerpt',
                    'status', 'priority',
                    'customer' => ['id', 'name', 'email', 'phone'],
                    'machine' => ['id', 'serial_number', 'location_label', 'is_active', 'product', 'address'],
                    'assigned_to', 'country_code',
                    'created_at', 'closed_at', 'is_trashed',
                    'description', 'internal_notes', 'resolution_notes',
                    'cancellation_reason', 'cancelled_by',
                    'media', 'status_history', 'deleted_at',
                ],
            ])
            ->assertJsonPath('data.id', $req->id)
            ->assertJsonPath('data.internal_notes', 'Confidential admin note');
    });

    it('returns 404 ERR_NOT_FOUND for unknown id', function (): void {
        [, $bearer] = adminMaintBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/maintenance-requests/9999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 403 for sales role', function (): void {
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        $req = adminMaintRequest($customer, $machine);

        [, $bearer] = adminMaintBearer(UserRole::Sales->value);
        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/maintenance-requests/{$req->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 200 for support role (read-only)', function (): void {
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        $req = adminMaintRequest($customer, $machine);

        [, $bearer] = adminMaintBearer(UserRole::Support->value);
        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/maintenance-requests/{$req->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $req->id);
    });

    it('returns 401 without a Bearer token', function (): void {
        ['customer' => $customer, 'machine' => $machine] = adminMaintMachine();
        $req = adminMaintRequest($customer, $machine);

        $this->getJson("/api/v1/admin/maintenance-requests/{$req->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
