<?php

declare(strict_types=1);

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\UserRole;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/maintenance-requests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.6 / § 5.7 /
| § 11.3.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function submitAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{customer: User, machine: Machine}
 */
function submitMachineStack(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
        'address_id' => $address->id,
        'is_active' => true,
    ])->create();

    return ['customer' => $customer, 'machine' => $machine];
}

describe('POST /api/v1/admin/maintenance-requests — submit on behalf', function (): void {
    it('returns 201 with the created request and fires MaintenanceRequestCreated', function (): void {
        Event::fake([MaintenanceRequestCreated::class]);
        [$admin, $bearer] = submitAdminBearer();
        ['customer' => $customer, 'machine' => $machine] = submitMachineStack();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::FilterChange->value,
                'description' => 'Customer phoned in; filter making noise.',
                'priority' => MaintenancePriority::Urgent->value,
                'internal_notes' => 'VIP customer — escalate if not fixed today.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', MaintenanceStatus::New->value)
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.internal_notes', 'VIP customer — escalate if not fixed today.')
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.machine.id', $machine->id);

        $created = MaintenanceRequest::query()->latest('id')->firstOrFail();
        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $created->id,
            'user_id' => $customer->id,
            'machine_id' => $machine->id,
            'status' => MaintenanceStatus::New->value,
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'maintenance_request_id' => $created->id,
            'from_status' => null,
            'to_status' => MaintenanceStatus::New->value,
            'actor_type' => MaintenanceActorType::Admin->value,
            'actor_id' => $admin->id,
        ]);

        Event::assertDispatched(MaintenanceRequestCreated::class);
    });

    it('returns 422 ERR_VALIDATION when machine_id is not owned by customer_id', function (): void {
        [, $bearer] = submitAdminBearer();
        ['customer' => $customer] = submitMachineStack();
        ['machine' => $otherMachine] = submitMachineStack(); // different customer owns this

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $otherMachine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'Mismatched ownership.',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['machine_id']]);
    });

    it('returns 422 when machine is inactive', function (): void {
        [, $bearer] = submitAdminBearer();
        ['customer' => $customer, 'machine' => $machine] = submitMachineStack();
        $machine->update(['is_active' => false]);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'Inactive machine should fail.',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['machine_id']]);
    });

    it('returns 422 ERR_VALIDATION when media_file_ids is supplied (prohibited)', function (): void {
        [, $bearer] = submitAdminBearer();
        ['customer' => $customer, 'machine' => $machine] = submitMachineStack();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'Should reject media at submit.',
                'media_file_ids' => [1, 2],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_file_ids']]);
    });

    it('returns 422 when customer_id is an admin (IsCustomer rule)', function (): void {
        [, $bearer] = submitAdminBearer();
        ['machine' => $machine] = submitMachineStack();
        $admin = User::factory()->admin(UserRole::Operations->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $admin->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'admin is not a customer',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['customer_id']]);
    });

    it('returns 403 ERR_FORBIDDEN for support role (no maintenance.create)', function (): void {
        [, $bearer] = submitAdminBearer(UserRole::Support->value);
        ['customer' => $customer, 'machine' => $machine] = submitMachineStack();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'Support should fail.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN for customer token', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;
        ['machine' => $machine] = submitMachineStack();

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/admin/maintenance-requests', [
                'customer_id' => $customer->id,
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'Customer cannot use admin endpoint.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a Bearer token', function (): void {
        $this->postJson('/api/v1/admin/maintenance-requests', [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
