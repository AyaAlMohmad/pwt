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
| PATCH /api/v1/admin/machines/{id}/maintenance-status
|--------------------------------------------------------------------------
|
| Delegates to the latest open maintenance request on the machine.
| If no open request exists, returns 404 ERR_NO_OPEN_MAINTENANCE_REQUEST.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function machineStatusAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function machineStatusBuildSetup(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
        'address_id' => $address->id,
    ])->create();

    return [$customer, $machine];
}

describe('PATCH /api/v1/admin/machines/{id}/maintenance-status', function (): void {
    it('transitions the latest open request via the machine endpoint', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $request = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => MaintenanceStatus::InProgress->value,
                'reason' => 'Technician dispatched',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::InProgress->value,
        ]);
    });

    it('auto-creates a maintenance request when none exists and transitions it', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => MaintenanceStatus::InProgress->value,
                'reason' => 'Technician dispatched',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value)
            ->assertJsonPath('data.issue_type', 'filter_change');

        $this->assertDatabaseHas('maintenance_requests', [
            'machine_id' => $machine->id,
            'status' => MaintenanceStatus::InProgress->value,
        ]);
    });

    it('auto-creates with custom issue_type and description when none exists', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => MaintenanceStatus::InProgress->value,
                'reason' => 'Technician dispatched',
                'issue_type' => 'leak',
                'description' => 'Water leaking from unit',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value)
            ->assertJsonPath('data.issue_type', 'leak')
            ->assertJsonPath('data.description', 'Water leaking from unit');

        $this->assertDatabaseHas('maintenance_requests', [
            'machine_id' => $machine->id,
            'status' => MaintenanceStatus::InProgress->value,
            'issue_type' => 'leak',
            'description' => 'Water leaking from unit',
        ]);
    });

    it('accepts a numeric status code (3 = in_progress)', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $request = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])
            ->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => 3,
                'reason' => 'Technician dispatched',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::InProgress->value,
        ]);
    });

    it('accepts a numeric status code as a string ("5" = cancelled)', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $request = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])
            ->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => '5',
                'reason' => 'Duplicate ticket',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::Cancelled->value,
        ]);
    });

    it('targets the latest open request when multiple exist', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $older = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])
            ->create();

        $newer = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])
            ->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $newer->id);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $newer->id,
            'status' => MaintenanceStatus::InProgress->value,
        ]);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $older->id,
            'status' => MaintenanceStatus::New->value,
        ]);
    });

    it('returns 403 for support role (no maintenance.update)', function (): void {
        [, $bearer] = machineStatusAdminBearer(UserRole::Support->value);
        [, $machine] = machineStatusBuildSetup();

        MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])
            ->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 for an unknown machine id', function (): void {
        [, $bearer] = machineStatusAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/machines/9999999/maintenance-status', [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a Bearer token', function (): void {
        [, $machine] = machineStatusBuildSetup();

        MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::New->value])
            ->create();

        $this->patchJson("/api/v1/admin/machines/{$machine->id}/maintenance-status", [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('includes latest_open_maintenance_request in the machine list payload', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $request = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::Assigned->value])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines');

        $response->assertOk()
            ->assertJsonPath('data.0.latest_open_maintenance_request.id', $request->id)
            ->assertJsonPath('data.0.latest_open_maintenance_request.status', MaintenanceStatus::Assigned->value);
    });
});

describe('POST /api/v1/admin/machines/{id}/maintenance-close', function (): void {
    it('closes the latest in_progress request via the machine endpoint', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $request = MaintenanceRequest::factory()->forUser($machine->user)->forMachine($machine)
            ->state(['status' => MaintenanceStatus::InProgress->value])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/machines/{$machine->id}/maintenance-close", [
                'resolution_notes' => 'Filter replaced from Machines page.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Completed->value)
            ->assertJsonPath('data.resolution_notes', 'Filter replaced from Machines page.');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::Completed->value,
        ]);
    });

    it('auto-creates and auto-transitions to in_progress before closing when no open request exists', function (): void {
        [, $bearer] = machineStatusAdminBearer();
        [, $machine] = machineStatusBuildSetup();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/machines/{$machine->id}/maintenance-close", [
                'resolution_notes' => 'Filter replaced from Machines page.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Completed->value)
            ->assertJsonPath('data.resolution_notes', 'Filter replaced from Machines page.');

        $this->assertDatabaseHas('maintenance_requests', [
            'machine_id' => $machine->id,
            'status' => MaintenanceStatus::Completed->value,
        ]);
    });

    it('returns 404 for an unknown machine id', function (): void {
        [, $bearer] = machineStatusAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/machines/9999999/maintenance-close', [])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
