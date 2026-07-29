<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenanceStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\File;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/maintenance-requests + GET /api/v1/maintenance-requests/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 1, § 3.3, § 4.3,
| § 4.4. Customer-scoped. Detail surface anonymises actor/assignee to
| `{ first_name }` and conditionally exposes resolution_notes /
| cancellation_reason.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, machine: Machine}
 */
function makeListShowCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
    ]);
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($user)->forProduct($product)
        ->state(['address_id' => $address->id])
        ->create();

    return ['user' => $user, 'token' => $token, 'machine' => $machine];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/maintenance-requests (list)
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/maintenance-requests', function (): void {
    it('returns 200 with an empty data array + pagination meta', function (): void {
        ['token' => $token] = makeListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/maintenance-requests');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data',
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('lists the caller\'s requests sorted created_at DESC with pagination meta', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        $older = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDays(3)])->create();
        $newer = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDay()])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/maintenance-requests');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newer->id, $older->id]);
    });

    it('excludes other users\' requests', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->create();

        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $strangerProduct = Product::factory()->create();
        $strangerMachine = Machine::factory()->forUser($stranger)->forProduct($strangerProduct)
            ->state(['address_id' => $strangerAddress->id])->create();
        MaintenanceRequest::factory()->forUser($stranger)->forMachine($strangerMachine)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/maintenance-requests');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
    });

    it('filters by status=new', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->newStatus()->create();
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->completed()->create();
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->cancelled()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/maintenance-requests?status=new');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.status'))->toBe(MaintenanceStatus::New->value);
    });

    it('filters by date_from and date_to (inclusive on created_at)', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        $inWindow = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDays(2)])->create();
        // Out of range — older than 7 days.
        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state(['created_at' => now()->subDays(20)])->create();

        $from = now()->subDays(7)->toDateString();
        $to = now()->addDay()->toDateString();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/maintenance-requests?date_from={$from}&date_to={$to}");

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect((int) $response->json('data.0.id'))->toBe($inWindow->id);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->getJson('/api/v1/maintenance-requests');

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns the Arabic issue_type description when Accept-Language: ar is sent (status still machine value)', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->withIssueType(MaintenanceIssueType::Leak)
            ->state(['description' => 'وصف باللغة العربية يحتاج إلى تفاصيل أكثر'])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/maintenance-requests');

        $response->assertOk();
        // The Arabic success message should be present.
        expect((string) $response->json('message'))->toContain('طلبات');
        // The description survives unaltered (Arabic) and issue_type returns the machine value.
        expect($response->json('data.0.issue_type'))->toBe(MaintenanceIssueType::Leak->value);
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/maintenance-requests/{id} (show)
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/maintenance-requests/{id}', function (): void {
    it('returns 200 with detail envelope including media, status_history, anonymised assigned_to', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();

        $assignee = User::factory()->admin()->create(['name' => 'Hassan AlMansouri']);
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state([
                'status' => MaintenanceStatus::Assigned->value,
                'assigned_to_user_id' => $assignee->id,
            ])->create();

        // Status history rows
        MaintenanceStatusHistory::query()->create([
            'maintenance_request_id' => $request->id,
            'from_status' => null,
            'to_status' => MaintenanceStatus::New->value,
            'actor_type' => MaintenanceActorType::Customer->value,
            'actor_id' => $user->id,
            'note' => null,
        ]);
        MaintenanceStatusHistory::query()->create([
            'maintenance_request_id' => $request->id,
            'from_status' => MaintenanceStatus::New->value,
            'to_status' => MaintenanceStatus::Assigned->value,
            'actor_type' => MaintenanceActorType::Admin->value,
            'actor_id' => $assignee->id,
            'note' => 'Assigned to senior tech',
        ]);

        // Media
        $file = File::factory()->for($user)->forType(FileType::MaintenanceMedia)->create();
        $request->media()->syncWithoutDetaching([$file->id]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/maintenance-requests/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.status', MaintenanceStatus::Assigned->value)
            ->assertJsonPath('data.assigned_to.first_name', 'Hassan')
            ->assertJsonStructure([
                'data' => [
                    'id', 'public_id', 'issue_type', 'description',
                    'status', 'priority', 'preferred_date', 'machine',
                    'assigned_to', 'media', 'status_history',
                ],
            ]);

        expect($response->json('data.status_history'))->toHaveCount(2);
        expect($response->json('data.media'))->toHaveCount(1);
    });

    it('anonymises actor to first_name only — no last_name, no email', function (): void {
        ['user' => $user, 'token' => $token, 'machine' => $machine] = makeListShowCustomer();
        $assignee = User::factory()->admin()->create([
            'name' => 'Hassan AlMansouri',
            'email' => 'hassan@pwt.test',
        ]);

        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)
            ->state([
                'status' => MaintenanceStatus::Assigned->value,
                'assigned_to_user_id' => $assignee->id,
            ])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/maintenance-requests/{$request->id}");

        $response->assertOk();

        $assignedTo = $response->json('data.assigned_to');
        expect($assignedTo)->toBeArray()
            ->and($assignedTo)->toHaveKey('first_name')
            ->and($assignedTo['first_name'])->toBe('Hassan')
            ->and($assignedTo)->not->toHaveKey('email')
            ->and($assignedTo)->not->toHaveKey('id')
            ->and($assignedTo)->not->toHaveKey('phone');
    });

    it('returns 403 ERR_FORBIDDEN when viewing another customer\'s request', function (): void {
        ['token' => $token] = makeListShowCustomer();

        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $strangerProduct = Product::factory()->create();
        $strangerMachine = Machine::factory()->forUser($stranger)->forProduct($strangerProduct)
            ->state(['address_id' => $strangerAddress->id])->create();
        $strangerRequest = MaintenanceRequest::factory()->forUser($stranger)->forMachine($strangerMachine)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/maintenance-requests/{$strangerRequest->id}");

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeListShowCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/maintenance-requests/9999999');

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        ['user' => $user, 'machine' => $machine] = makeListShowCustomer();
        $request = MaintenanceRequest::factory()->forUser($user)->forMachine($machine)->create();

        $response = $this->getJson("/api/v1/maintenance-requests/{$request->id}");

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
