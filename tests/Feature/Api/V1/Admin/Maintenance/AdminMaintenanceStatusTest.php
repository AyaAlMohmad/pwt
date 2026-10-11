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
| PATCH /api/v1/admin/maintenance-requests/{id}/status
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.8 / § 5.9.
| Allowed payload values: in_progress, cancelled (with reason).
| `assigned` → 409 ERR_USE_ASSIGN_ENDPOINT.
| `completed` → 409 ERR_USE_CLOSE_ENDPOINT.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function statusAdminBearerMaint(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function statusBuildRequest(array $overrides = []): MaintenanceRequest
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
        'address_id' => $address->id,
    ])->create();
    $technician = User::factory()->admin(UserRole::Operations->value)->create();

    return MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
        ->state(array_merge([
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $technician->id,
        ], $overrides))
        ->create();
}

describe('PATCH /api/v1/admin/maintenance-requests/{id}/status', function (): void {
    it('transitions assigned → in_progress and returns the detail envelope', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(); // assigned

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::InProgress->value,
                'note' => 'Technician on site',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::InProgress->value,
        ]);
    });

    it('accepts a numeric status code (3 = in_progress)', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(); // assigned

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => 3,
                'note' => 'Technician on site',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value);
    });

    it('accepts a numeric status code as a string ("5" = cancelled)', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::New->value, 'assigned_to_user_id' => null]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => '5',
                'reason' => 'Duplicate ticket — closing.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value);
    });

    it('rejects an unknown numeric status code with 422', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => 99,
            ])
            ->assertStatus(422);
    });

    it('rejects status=assigned with 409 ERR_USE_ASSIGN_ENDPOINT', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::New->value, 'assigned_to_user_id' => null]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Assigned->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_USE_ASSIGN_ENDPOINT');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::New->value,
        ]);
    });

    it('rejects status=completed with 409 ERR_USE_CLOSE_ENDPOINT', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::InProgress->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Completed->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_USE_CLOSE_ENDPOINT');
    });

    it('cancels from new with reason — 200', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::New->value, 'assigned_to_user_id' => null]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Cancelled->value,
                'reason' => 'Duplicate ticket — closing.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::Cancelled->value,
            'cancellation_reason' => 'Duplicate ticket — closing.',
        ]);
    });

    it('cancels from assigned (broader admin window) — 200', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::Assigned->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Cancelled->value,
                'reason' => 'Customer unreachable.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value);
    });

    it('cancels from in_progress — 200', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::InProgress->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Cancelled->value,
                'reason' => 'Technician aborted on-site.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value);
    });

    it('rejects cancellation without reason with 422', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::Assigned->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Cancelled->value,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    });

    it('allows new → in_progress (technician assignment is optional)', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest(['status' => MaintenanceStatus::New->value, 'assigned_to_user_id' => null]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::InProgress->value);
    });

    it('rejects cancelling an already-completed request with 409', function (): void {
        [, $bearer] = statusAdminBearerMaint();
        $req = statusBuildRequest([
            'status' => MaintenanceStatus::Completed->value,
            'resolution_notes' => 'done',
            'closed_at' => now()->subHour(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::Cancelled->value,
                'reason' => 'Should fail terminal cancel.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 403 for support role', function (): void {
        [, $bearer] = statusAdminBearerMaint(UserRole::Support->value);
        $req = statusBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 for unknown id', function (): void {
        [, $bearer] = statusAdminBearerMaint();

        $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/maintenance-requests/9999999/status', [
                'status' => MaintenanceStatus::InProgress->value,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a Bearer token', function (): void {
        $req = statusBuildRequest();

        $this->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/status", [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
