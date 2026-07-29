<?php

declare(strict_types=1);

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/admin/maintenance-requests/{id}/assign
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.7 / § 5.8 /
| § 11.3.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function assignAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function assignBuildRequest(array $overrides = []): MaintenanceRequest
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($customer)->forProduct($product)->state([
        'address_id' => $address->id,
    ])->create();

    return MaintenanceRequest::factory()->forUser($customer)->forMachine($machine)
        ->state(array_merge(['status' => MaintenanceStatus::New->value], $overrides))
        ->create();
}

describe('PATCH /api/v1/admin/maintenance-requests/{id}/assign', function (): void {
    it('assigns a technician (operations admin) from status=new and transitions to assigned', function (): void {
        [$admin, $bearer] = assignAdminBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $req = assignBuildRequest();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $technician->id,
                'note' => 'Hassan to handle UAE region',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Assigned->value)
            ->assertJsonPath('data.assigned_to.id', $technician->id);

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $req->id,
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $technician->id,
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'maintenance_request_id' => $req->id,
            'from_status' => MaintenanceStatus::New->value,
            'to_status' => MaintenanceStatus::Assigned->value,
            'actor_type' => MaintenanceActorType::Admin->value,
            'actor_id' => $admin->id,
        ]);

        $log = Activity::query()->where('log_name', 'admin.maintenance')
            ->where('event', 'admin.maintenance.assigned')->latest('id')->first();
        expect($log)->not->toBeNull();
        expect($log->causer_id)->toBe($admin->id);
    });

    it('returns 422 when assigned_to_user_id is a non-operations admin (e.g. sales)', function (): void {
        [, $bearer] = assignAdminBearer();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();
        $req = assignBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $sales->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assigned_to_user_id']]);
    });

    it('returns 422 when assigned_to_user_id is a customer', function (): void {
        [, $bearer] = assignAdminBearer();
        $customer = User::factory()->customer()->create();
        $req = assignBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $customer->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['assigned_to_user_id']]);
    });

    it('re-assigns an already-assigned request (idempotent path) and appends a notes-only history row', function (): void {
        [, $bearer] = assignAdminBearer();
        $first = User::factory()->admin(UserRole::Operations->value)->create();
        $second = User::factory()->admin(UserRole::Operations->value)->create();
        $req = assignBuildRequest([
            'status' => MaintenanceStatus::Assigned->value,
            'assigned_to_user_id' => $first->id,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $second->id,
                'note' => 'Re-assigned to Ali — Hassan unavailable.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceStatus::Assigned->value)
            ->assertJsonPath('data.assigned_to.id', $second->id);

        // History should now include the notes-only re-assignment row.
        $historyRows = MaintenanceStatusHistory::query()
            ->where('maintenance_request_id', $req->id)
            ->where('from_status', MaintenanceStatus::Assigned->value)
            ->where('to_status', MaintenanceStatus::Assigned->value)
            ->get();

        expect($historyRows)->toHaveCount(1);
        expect($historyRows->first()->note)->toBe('Re-assigned to Ali — Hassan unavailable.');
    });

    it('rejects assigning a cancelled request with 409 ERR_INVALID_STATUS_TRANSITION', function (): void {
        [, $bearer] = assignAdminBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $req = assignBuildRequest([
            'status' => MaintenanceStatus::Cancelled->value,
            'cancellation_reason' => 'no longer needed',
            'closed_at' => now()->subHour(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $technician->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('rejects assigning a completed request with 409', function (): void {
        [, $bearer] = assignAdminBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $req = assignBuildRequest([
            'status' => MaintenanceStatus::Completed->value,
            'resolution_notes' => 'done',
            'closed_at' => now()->subHour(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $technician->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 403 ERR_FORBIDDEN for support role', function (): void {
        [, $bearer] = assignAdminBearer(UserRole::Support->value);
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $req = assignBuildRequest();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [
                'assigned_to_user_id' => $technician->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 for unknown id', function (): void {
        [, $bearer] = assignAdminBearer();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/maintenance-requests/9999999/assign', [
                'assigned_to_user_id' => $technician->id,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a Bearer token', function (): void {
        $req = assignBuildRequest();

        $this->patchJson("/api/v1/admin/maintenance-requests/{$req->id}/assign", [])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
