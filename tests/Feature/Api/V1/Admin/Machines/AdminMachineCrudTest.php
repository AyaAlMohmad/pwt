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
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/machines + PUT /api/v1/admin/machines/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.3 / § 3.4
| / § 5.4 / § 5.5 / § 11.2.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function crudAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{customer: User, address: Address, product: Product}
 */
function crudMachinePayloadStack(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return ['customer' => $customer, 'address' => $address, 'product' => $product];
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/admin/machines — store', function (): void {
    it('creates a machine for a customer with explicit fields and stamps source=created', function (): void {
        [$admin, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();

        $payload = [
            'user_id' => $customer->id,
            'address_id' => $address->id,
            'product_id' => $product->id,
            'serial_number' => 'PW50M-AE-NEW-1',
            'term' => ProductTerm::Buy->value,
            'installed_at' => now()->subMonth()->toDateString(),
            'filter_change_interval_months' => 6,
            'country_code' => 'ae',
            'notes' => 'Admin-created',
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.serial_number', 'PW50M-AE-NEW-1')
            ->assertJsonPath('data.source', MachineSource::Created->value);

        // Source must be forced server-side regardless of body.
        $this->assertDatabaseHas('machines', [
            'serial_number' => 'PW50M-AE-NEW-1',
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'source' => MachineSource::Created->value,
            'order_id' => null,
            'order_installation_id' => null,
        ]);

        // Scheduler should compute next due (6 months from installed_at).
        $created = Machine::query()->where('serial_number', 'PW50M-AE-NEW-1')->firstOrFail();
        expect($created->next_maintenance_due_at)->not->toBeNull();

        // Activity log row with explicit IP + actor.
        $entry = Activity::query()->where('log_name', 'admin.machines')
            ->where('event', 'admin.machine.created')->latest('id')->first();
        expect($entry)->not->toBeNull();
        expect($entry->causer_id)->toBe($admin->id);
    });

    it('rejects when address_id does not belong to user_id — 422 ERR_VALIDATION', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'product' => $product] = crudMachinePayloadStack();
        $otherCustomer = User::factory()->customer()->create();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $foreignAddress = Address::factory()->forUser($otherCustomer)->forCountry($uae)->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $customer->id,
                'address_id' => $foreignAddress->id,
                'product_id' => $product->id,
                'serial_number' => 'PW50M-FAIL-1',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'country_code' => 'ae',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['address_id']]);
    });

    it('rejects when product is inactive — 422 ERR_VALIDATION on product_id', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address] = crudMachinePayloadStack();
        $inactive = Product::factory()->inactive()->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $customer->id,
                'address_id' => $address->id,
                'product_id' => $inactive->id,
                'serial_number' => 'PW50M-INACT-1',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'country_code' => 'ae',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['product_id']]);
    });

    it('rejects when user_id is not a customer — 422', function (): void {
        [, $bearer] = crudAdminBearer();
        ['address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $admin = User::factory()->admin(UserRole::Operations->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $admin->id,
                'address_id' => $address->id,
                'product_id' => $product->id,
                'serial_number' => 'PW50M-ADMIN-1',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'country_code' => 'ae',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['user_id']]);
    });

    it('rejects duplicate serial number with 422', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        Machine::factory()->forUser($customer)->forProduct($product)->state([
            'address_id' => $address->id,
            'serial_number' => 'DUP-001',
        ])->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $customer->id,
                'address_id' => $address->id,
                'product_id' => $product->id,
                'serial_number' => 'DUP-001',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'country_code' => 'ae',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['serial_number']]);
    });

    it('rejects explicit next_maintenance_due_at without an interval (cross-field rule)', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $customer->id,
                'address_id' => $address->id,
                'product_id' => $product->id,
                'serial_number' => 'PW50M-NO-INT-1',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'next_maintenance_due_at' => now()->addMonth()->toDateString(),
                'country_code' => 'ae',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['next_maintenance_due_at']]);
    });

    it('rejects prohibited fields (source, order_id, …)', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines', [
                'user_id' => $customer->id,
                'address_id' => $address->id,
                'product_id' => $product->id,
                'serial_number' => 'PW50M-PROHIBITED-1',
                'term' => ProductTerm::Buy->value,
                'installed_at' => now()->subMonth()->toDateString(),
                'country_code' => 'ae',
                'source' => 'imported',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['source']]);
    });

    it('returns 403 ERR_FORBIDDEN for sales/support roles', function (): void {
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();

        foreach ([UserRole::Sales->value, UserRole::Support->value] as $role) {
            [, $bearer] = crudAdminBearer($role);

            $this->withHeader('Authorization', $bearer)
                ->postJson('/api/v1/admin/machines', [
                    'user_id' => $customer->id,
                    'address_id' => $address->id,
                    'product_id' => $product->id,
                    'serial_number' => 'PW50M-FORBIDDEN-'.$role,
                    'term' => ProductTerm::Buy->value,
                    'installed_at' => now()->subMonth()->toDateString(),
                    'country_code' => 'ae',
                ])
                ->assertStatus(403)
                ->assertJsonPath('code', 'ERR_FORBIDDEN');
        }
    });

    it('returns 401 without a token', function (): void {
        $this->postJson('/api/v1/admin/machines', [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| PUT
|--------------------------------------------------------------------------
*/

describe('PUT /api/v1/admin/machines/{id} — update', function (): void {
    it('updates allowed fields and persists the change', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'PUT-ORIG-1', 'filter_change_interval_months' => 12])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$machine->id}", [
                'serial_number' => 'PUT-NEW-1',
                'location_label' => 'Updated label',
                'notes' => 'Touched up',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.serial_number', 'PUT-NEW-1')
            ->assertJsonPath('data.location_label', 'Updated label')
            ->assertJsonPath('data.notes', 'Touched up');

        $this->assertDatabaseHas('machines', [
            'id' => $machine->id,
            'serial_number' => 'PUT-NEW-1',
        ]);
    });

    it('recomputes next_maintenance_due_at when filter_change_interval_months changes (no explicit override)', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
            'address_id' => $address->id,
            'serial_number' => 'PUT-RECOMPUTE-1',
            'installed_at' => now()->subMonths(2)->toDateString(),
            'filter_change_interval_months' => 12,
            'next_maintenance_due_at' => now()->addMonths(10)->toDateString(),
        ])->create();

        $originalDue = $machine->next_maintenance_due_at?->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$machine->id}", [
                'filter_change_interval_months' => 3,
            ]);

        $response->assertOk();
        $machine->refresh();
        expect($machine->filter_change_interval_months)->toBe(3);
        expect($machine->next_maintenance_due_at?->toDateString())->not->toBe($originalDue);
    });

    it('uses explicit next_maintenance_due_at verbatim (override wins over recompute)', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
            'address_id' => $address->id,
            'serial_number' => 'PUT-OVERRIDE-1',
            'installed_at' => now()->subMonths(2)->toDateString(),
            'filter_change_interval_months' => 12,
        ])->create();

        $explicit = now()->addDays(45)->toDateString();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$machine->id}", [
                'filter_change_interval_months' => 6,
                'next_maintenance_due_at' => $explicit,
            ])
            ->assertOk()
            ->assertJsonPath('data.next_maintenance_due_at', $explicit);

        $machine->refresh();
        expect($machine->next_maintenance_due_at->toDateString())->toBe($explicit);
    });

    it('returns 404 for an unknown id', function (): void {
        [, $bearer] = crudAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/machines/9999999', [
                'serial_number' => 'NOPE',
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('rejects soft-deleted machine updates with 409 ERR_RESOURCE_TRASHED', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();
        $machine->delete();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$machine->id}", ['notes' => 'should fail'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_RESOURCE_TRASHED');
    });

    it('returns 422 when serial_number collides with another machine', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'CONFLICT-001'])
            ->create();
        $target = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'TARGET-001'])
            ->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$target->id}", [
                'serial_number' => 'CONFLICT-001',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['serial_number']]);
    });

    it('rejects prohibited user_id / product_id (immutable)', function (): void {
        [, $bearer] = crudAdminBearer();
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/machines/{$machine->id}", [
                'user_id' => $customer->id + 1,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['user_id']]);
    });

    it('returns 403 ERR_FORBIDDEN for sales/support', function (): void {
        ['customer' => $customer, 'address' => $address, 'product' => $product] = crudMachinePayloadStack();
        $machine = Machine::factory()->forUser($customer)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        foreach ([UserRole::Sales->value, UserRole::Support->value] as $role) {
            [, $bearer] = crudAdminBearer($role);
            $this->withHeader('Authorization', $bearer)
                ->putJson("/api/v1/admin/machines/{$machine->id}", ['notes' => 'x'])
                ->assertStatus(403)
                ->assertJsonPath('code', 'ERR_FORBIDDEN');
        }
    });
});
