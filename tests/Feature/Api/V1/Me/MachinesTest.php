<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/machines + GET /api/v1/me/machines/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/machines-maintenance.md § 1, § 3.2, § 4.1,
| § 4.2 and § 6. Customer-scoped read of installed filter units. Owner-
| only — ownership failure returns 403, NOT 404 (CLAUDE.md § 7.3).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makeMachinesCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
        'city' => 'Dubai',
    ]);

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/machines
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/me/machines', function (): void {
    it('returns 200 with an empty data array + pagination meta for a fresh user', function (): void {
        ['token' => $token] = makeMachinesCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data',
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to']],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('lists the caller\'s machines with pagination meta', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        Machine::factory()
            ->count(3)
            ->forUser($user)
            ->forProduct($product)
            ->state(['address_id' => $address->id, 'country_code' => 'ae'])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'public_id', 'serial_number',
                        'product' => ['id', 'code', 'name'],
                        'location_label', 'country_code', 'installed_at',
                        'next_maintenance_due_at', 'is_active', 'term',
                        'address' => ['id', 'label', 'city', 'country'],
                        'needs_maintenance',
                        'product_price', 'rental_price', 'rental_total',
                    ],
                ],
            ]);
    });

    it('excludes other users\' machines', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        Machine::factory()->forUser($user)
            ->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'MINE-001'])
            ->create();

        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        Machine::factory()->forUser($stranger)
            ->forProduct($product)
            ->state(['address_id' => $strangerAddress->id, 'serial_number' => 'OTHER-001'])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
        $serials = collect($response->json('data'))->pluck('serial_number')->all();
        expect($serials)->toBe(['MINE-001']);
    });

    it('filters by search on serial_number', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'PW50M-AE-001234'])
            ->create();
        Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id, 'serial_number' => 'PW90-AE-999000'])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines?search=001234');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.serial_number'))->toBe('PW50M-AE-001234');
    });

    it('filters by search on location_label', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        Machine::factory()->forUser($user)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'serial_number' => 'X-1',
                'location_label' => 'Eataly Main Kitchen - Dubai Mall',
            ])
            ->create();
        Machine::factory()->forUser($user)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'serial_number' => 'X-2',
                'location_label' => 'Marina Office',
            ])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines?search=Eataly');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 1);
        expect($response->json('data.0.location_label'))->toBe('Eataly Main Kitchen - Dubai Mall');
    });

    it('filters by needs_maintenance=true (only due within 30 days or overdue)', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        // Due tomorrow → should appear.
        Machine::factory()->forUser($user)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'serial_number' => 'DUE-1',
                'next_maintenance_due_at' => now()->addDay()->toDateString(),
            ])->create();

        // Overdue → should appear.
        Machine::factory()->forUser($user)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'serial_number' => 'DUE-2',
                'next_maintenance_due_at' => now()->subDays(5)->toDateString(),
            ])->create();

        // Due in 90 days → should NOT appear.
        Machine::factory()->forUser($user)->forProduct($product)
            ->state([
                'address_id' => $address->id,
                'serial_number' => 'NOT-DUE',
                'next_maintenance_due_at' => now()->addDays(90)->toDateString(),
            ])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines?needs_maintenance=1');

        $response->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $serials = collect($response->json('data'))->pluck('serial_number')->all();
        expect($serials)->toContain('DUE-1')
            ->and($serials)->toContain('DUE-2')
            ->and($serials)->not->toContain('NOT-DUE');
    });

    it('returns the product name translated when Accept-Language: ar is sent', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();

        $product = Product::factory()->create([
            'name' => ['en' => 'PW50M Filtration Unit', 'ar' => 'وحدة بي دبليو ٥٠ إم'],
        ]);
        Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/me/machines');

        $response->assertOk();
        expect($response->json('data.0.product.name'))->toBe('وحدة بي دبليو ٥٠ إم');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->getJson('/api/v1/me/machines');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/machines/{id}
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/me/machines/{id}', function (): void {
    it('returns 200 with the detail shape + maintenance logs for the owner', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();

        $machine = Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id, 'filter_change_interval_months' => 3])
            ->create();

        MachineMaintenanceLog::factory()
            ->count(3)
            ->forMachine($machine)
            ->completed()
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/machines/{$machine->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.filter_change_interval_months', 3)
            ->assertJsonPath('data.total_logs_count', 3)
            ->assertJsonStructure([
                'data' => [
                    'id', 'public_id', 'serial_number', 'product',
                    'location_label', 'country_code', 'installed_at',
                    'next_maintenance_due_at', 'is_active', 'term',
                    'address', 'needs_maintenance',
                    'product_price', 'rental_price', 'rental_total',
                    'filter_change_interval_months', 'notes',
                    'maintenance_logs', 'total_logs_count', 'open_requests_count',
                    'latest_completed_log_at',
                ],
            ]);
    });

    it('returns 403 ERR_FORBIDDEN when viewing another customer\'s machine', function (): void {
        ['token' => $token] = makeMachinesCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($stranger)->forProduct($product)
            ->state(['address_id' => $strangerAddress->id])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/machines/{$machine->id}");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeMachinesCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines/9999999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        ['user' => $user, 'address' => $address] = makeMachinesCustomer();
        $product = Product::factory()->create();
        $machine = Machine::factory()->forUser($user)->forProduct($product)
            ->state(['address_id' => $address->id])->create();

        $response = $this->getJson("/api/v1/me/machines/{$machine->id}");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 404 when the id is non-numeric (route constraint rejects)', function (): void {
        ['token' => $token] = makeMachinesCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/machines/abc');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
