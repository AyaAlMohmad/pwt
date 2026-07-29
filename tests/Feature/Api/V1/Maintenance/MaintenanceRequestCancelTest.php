<?php

declare(strict_types=1);

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceStatus;
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
| POST /api/v1/maintenance-requests/{id}/cancel
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 2.3, § 5.4.
| Customer cancel is allowed only from `new`; otherwise 409
| ERR_INVALID_STATUS_TRANSITION. Ownership → 403. Not found → 404.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, machine: Machine}
 */
function makeCancelMaintenanceCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($user)->forProduct($product)
        ->state(['address_id' => $address->id])->create();

    return ['user' => $user, 'token' => $token, 'machine' => $machine];
}

/*
|--------------------------------------------------------------------------
| Happy path — cancel from `new`
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests/{id}/cancel — allowed', function (): void {
    it('cancels a request in `new` state and writes a status_history row', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();

        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->newStatus()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'No longer need this maintenance visit',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.status', MaintenanceStatus::Cancelled->value)
            ->assertJsonPath('data.cancellation_reason', 'No longer need this maintenance visit');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::Cancelled->value,
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'maintenance_request_id' => $request->id,
            'from_status' => MaintenanceStatus::New->value,
            'to_status' => MaintenanceStatus::Cancelled->value,
            'actor_type' => MaintenanceActorType::Customer->value,
            'actor_id' => $user->id,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Forbidden transitions → 409
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests/{id}/cancel — forbidden transitions', function (): void {
    it('returns 409 ERR_INVALID_STATUS_TRANSITION when the request is `assigned`', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->assigned()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $request->id,
            'status' => MaintenanceStatus::Assigned->value,
        ]);
    });

    it('returns 409 when the request is `in_progress`', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->inProgress()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 when the request is already `completed`', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->completed()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 when the request is already `cancelled`', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->cancelled()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });
});

/*
|--------------------------------------------------------------------------
| Auth / ownership / validation / not-found
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests/{id}/cancel — auth & validation', function (): void {
    it('returns 403 ERR_FORBIDDEN when cancelling another customer\'s request', function (): void {
        ['token' => $token] = makeCancelMaintenanceCustomer();

        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $strangerProduct = Product::factory()->create();
        $strangerMachine = Machine::factory()->forUser($stranger)->forProduct($strangerProduct)
            ->state(['address_id' => $strangerAddress->id])->create();
        $strangerRequest = MaintenanceRequest::factory()->forUser($stranger)->forMachine($strangerMachine)
            ->newStatus()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$strangerRequest->id}/cancel", [
                'cancellation_reason' => 'Not mine to cancel',
            ]);

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');

        $this->assertDatabaseHas('maintenance_requests', [
            'id' => $strangerRequest->id,
            'status' => MaintenanceStatus::New->value,
        ]);
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeCancelMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests/9999999/cancel', [
                'cancellation_reason' => 'Ghost request',
            ]);

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 422 ERR_VALIDATION when cancellation_reason is missing', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    });

    it('returns 422 when cancellation_reason is under 5 chars', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
                'cancellation_reason' => 'no',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        ['user' => $user, 'machine' => $machine] = makeCancelMaintenanceCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();

        $response = $this->postJson("/api/v1/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'no token',
        ]);

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
