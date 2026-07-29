<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/maintenance-requests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 1, § 3.1, § 5.1.
| Customer submits a maintenance request against an OWNED, ACTIVE machine.
| Ownership failure of `machine_id` surfaces as 422 (not 403/404) to avoid
| leaking machine existence. Rate limit 10/min/user.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('maintenance.create');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, machine: Machine}
 */
function makeMaintenanceCustomer(array $machineOverrides = []): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
    ]);
    $product = Product::factory()->create();
    $machine = Machine::factory()
        ->forUser($user)
        ->forProduct($product)
        ->state(array_merge([
            'address_id' => $address->id,
            'is_active' => true,
        ], $machineOverrides))
        ->create();

    RateLimiter::clear('maintenance.create:'.$user->id);

    return ['user' => $user, 'token' => $token, 'machine' => $machine];
}

function maintenanceMediaFile(User $user): File
{
    return File::factory()->for($user)->forType(FileType::MaintenanceMedia)->create();
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests — happy path', function (): void {
    it('returns 201 with the detail envelope and writes the initial status_history row', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'There is a noticeable leak under the kitchen sink for the past two days.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', MaintenanceStatus::New->value)
            ->assertJsonPath('data.issue_type', MaintenanceIssueType::Leak->value)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'issue_type', 'description', 'status',
                    'priority', 'preferred_date', 'machine', 'created_at',
                    'assigned_to', 'media', 'status_history',
                ],
            ]);

        $this->assertDatabaseHas('maintenance_requests', [
            'user_id' => $user->id,
            'machine_id' => $machine->id,
            'status' => MaintenanceStatus::New->value,
            'issue_type' => MaintenanceIssueType::Leak->value,
        ]);

        $this->assertDatabaseHas('maintenance_status_history', [
            'from_status' => null,
            'to_status' => MaintenanceStatus::New->value,
            'actor_type' => MaintenanceActorType::Customer->value,
            'actor_id' => $user->id,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests — validation', function (): void {
    it('returns 422 with errors.machine_id when machine_id is missing', function (): void {
        ['token' => $token] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['machine_id']]);
    });

    it('returns 422 with errors.machine_id when machine_id belongs to another user', function (): void {
        ['token' => $token] = makeMaintenanceCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $foreignMachine = Machine::factory()->forUser($stranger)->forProduct($product)
            ->state(['address_id' => $strangerAddress->id])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $foreignMachine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['machine_id']]);
    });

    it('returns 422 with errors.machine_id when the machine is inactive', function (): void {
        ['token' => $token] = makeMaintenanceCustomer(['is_active' => false]);
        ['machine' => $machine] = makeMaintenanceCustomer(['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['machine_id']]);
    });

    it('returns 422 with errors.issue_type for an invalid enum value', function (): void {
        ['token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => 'totally-bogus',
                'description' => 'A meaningful description of the issue.',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['issue_type']]);
    });

    it('returns 422 with errors.description when description is missing', function (): void {
        ['token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['description']]);
    });

    it('returns 422 when description is longer than 2000 chars', function (): void {
        ['token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['description']]);
    });

    it('returns 422 when preferred_date is in the past', function (): void {
        ['token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'preferred_date' => now()->subDay()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['preferred_date']]);
    });

    it('returns 422 when a media_file_ids entry is not owned by the caller', function (): void {
        ['token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerFile = File::factory()->for($stranger)->forType(FileType::MaintenanceMedia)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'media_file_ids' => [$strangerFile->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_file_ids.0']]);
    });

    it('returns 422 when a media_file_ids entry is not of type maintenance_media', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();
        $wrongType = File::factory()->for($user)->forType(FileType::ProductImage)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'media_file_ids' => [$wrongType->id],
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_file_ids.0']]);
    });

    it('returns 422 when media_file_ids contains more than 5 entries', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();
        $files = collect(range(1, 6))->map(fn () => maintenanceMediaFile($user));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'media_file_ids' => $files->pluck('id')->all(),
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_file_ids']]);
    });
});

/*
|--------------------------------------------------------------------------
| Media attachments — happy path with ≤ 5 files
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests — media attachments', function (): void {
    it('attaches up to 5 maintenance_media files via the pivot table', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();
        $files = collect(range(1, 4))->map(fn () => maintenanceMediaFile($user));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::WaterQuality->value,
                'description' => 'There are visible particles coming through the tap.',
                'media_file_ids' => $files->pluck('id')->all(),
            ]);

        $response->assertCreated();
        $requestId = (int) $response->json('data.id');

        foreach ($files as $file) {
            $this->assertDatabaseHas('maintenance_request_media', [
                'maintenance_request_id' => $requestId,
                'file_id' => $file->id,
            ]);
        }
    });
});

/*
|--------------------------------------------------------------------------
| Priority coercion — customer cannot escalate above medium
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests — priority coercion', function (): void {
    it('silently coerces customer-supplied "high" priority down to "medium"', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'priority' => 'high',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', MaintenancePriority::Medium->value);

        $this->assertDatabaseHas('maintenance_requests', [
            'user_id' => $user->id,
            'priority' => MaintenancePriority::Medium->value,
        ]);
    });

    it('silently coerces customer-supplied "urgent" priority down to "medium"', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'priority' => 'urgent',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', MaintenancePriority::Medium->value);
    });

    it('keeps "low" priority as low when the customer supplies it', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/maintenance-requests', [
                'machine_id' => $machine->id,
                'issue_type' => MaintenanceIssueType::Leak->value,
                'description' => 'A meaningful description of the issue.',
                'priority' => 'low',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', MaintenancePriority::Low->value);
    });
});

/*
|--------------------------------------------------------------------------
| Auth + rate limit
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/maintenance-requests — auth & rate limit', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->postJson('/api/v1/maintenance-requests', [
            'machine_id' => 1,
            'issue_type' => MaintenanceIssueType::Leak->value,
            'description' => 'A meaningful description of the issue.',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 429 after exceeding the 10/min/user limiter', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeMaintenanceCustomer();

        $hit429 = false;
        for ($i = 0; $i < 12; $i++) {
            $resp = $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/maintenance-requests', [
                    'machine_id' => $machine->id,
                    'issue_type' => MaintenanceIssueType::Leak->value,
                    'description' => 'Rate-limit probe #'.$i.' to exhaust the bucket.',
                ]);
            if ($resp->status() === 429) {
                $hit429 = true;
                break;
            }
        }

        expect($hit429)->toBeTrue();
    });
});
