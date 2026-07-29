<?php

declare(strict_types=1);

use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenanceLogStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/maintenance-requests/{id}/close
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.9 / § 5.10
| / § 11.1.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function closeAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * @return array{request: MaintenanceRequest, machine: Machine, technician: User}
 */
function closeBuildRequest(array $overrides = [], array $machineOverrides = []): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state(array_merge([
        'address_id' => $address->id,
        'filter_change_interval_months' => 6,
        'installed_at' => now()->subMonths(2)->toDateString(),
    ], $machineOverrides))->create();
    $technician = User::factory()->admin(UserRole::Operations->value)->create();

    $request = MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
        ->state(array_merge([
            'status' => MaintenanceStatus::InProgress->value,
            'assigned_to_user_id' => $technician->id,
        ], $overrides))
        ->create();

    return ['request' => $request, 'machine' => $machine, 'technician' => $technician];
}

describe('POST /api/v1/admin/maintenance-requests/{id}/close', function (): void {
    it('closes an in_progress request and creates a machine_maintenance_logs row', function (): void {
        [$admin, $bearer] = closeAdminBearer();
        ['request' => $req, 'machine' => $machine, 'technician' => $technician] = closeBuildRequest();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Replaced filter cartridge, flow verified.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Completed->value)
            ->assertJsonPath('data.resolution_notes', 'Replaced filter cartridge, flow verified.');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::Completed->value,
        ]);

        $log = MachineMaintenanceLog::query()->where('maintenance_request_id', $req->id)->first();
        expect($log)->not->toBeNull()
            ->and($log->machine_id)->toBe($machine->id)
            ->and($log->status->value)->toBe(MaintenanceLogStatus::Completed->value)
            ->and($log->source->value)->toBe(MaintenanceLogSource::Request->value)
            ->and($log->technician_user_id)->toBe($technician->id)
            ->and($log->created_by_user_id)->toBe($admin->id)
            ->and($log->notes)->toBe('Replaced filter cartridge, flow verified.');
    });

    it('refreshes machine next_maintenance_due_at via the scheduler', function (): void {
        [, $bearer] = closeAdminBearer();
        ['request' => $req, 'machine' => $machine] = closeBuildRequest(
            machineOverrides: [
                'filter_change_interval_months' => 3,
                'next_maintenance_due_at' => now()->subDay()->toDateString(),
            ],
        );

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Closed via close endpoint',
            ])
            ->assertOk();

        $machine->refresh();
        expect($machine->next_maintenance_due_at)->not->toBeNull();
        expect($machine->next_maintenance_due_at->greaterThan(now()))->toBeTrue();
    });

    it('rejects closing from non-in_progress with 409', function (): void {
        [, $bearer] = closeAdminBearer();
        ['request' => $req] = closeBuildRequest(['status' => MaintenanceStatus::Assigned->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Should reject',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('rejects completed_at in the future with 422', function (): void {
        [, $bearer] = closeAdminBearer();
        ['request' => $req] = closeBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Future date',
                'completed_at' => now()->addDays(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['completed_at']]);
    });

    it('rejects completed_at before the machine installed_at with 422', function (): void {
        [, $bearer] = closeAdminBearer();
        ['request' => $req] = closeBuildRequest(
            machineOverrides: ['installed_at' => now()->subDays(10)->toDateString()],
        );

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Pre-install date',
                'completed_at' => now()->subDays(30)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['completed_at']]);
    });

    it('rejects missing resolution_notes with 422', function (): void {
        [, $bearer] = closeAdminBearer();
        ['request' => $req] = closeBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['resolution_notes']]);
    });

    it('returns 403 for support role', function (): void {
        [, $bearer] = closeAdminBearer(UserRole::Support->value);
        ['request' => $req] = closeBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
                'resolution_notes' => 'Support cannot close.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 for an unknown id', function (): void {
        [, $bearer] = closeAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/maintenance-requests/9999999/close', [
                'resolution_notes' => 'No such request.',
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a Bearer token', function (): void {
        ['request' => $req] = closeBuildRequest();

        $this->postJson("/api/v1/admin/maintenance-requests/{$req->id}/close", [
            'resolution_notes' => 'No auth.',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
