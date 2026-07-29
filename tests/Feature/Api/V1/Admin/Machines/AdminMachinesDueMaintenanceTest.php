<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/machines/due-maintenance
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-machines-maintenance.md § 3.2 / § 4.1 /
| § 6.1. Forced sort: next_maintenance_due_at ASC, id ASC.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function dueAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function dueMachine(array $overrides = []): Machine
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return Machine::factory()->forUser($customer)->forProduct($product)->state(array_merge([
        'address_id' => $address->id,
        'country_code' => 'ae',
        'is_active' => true,
    ], $overrides))->create();
}

describe('GET /api/v1/admin/machines/due-maintenance', function (): void {
    it('returns machines due within the default 30 days', function (): void {
        [, $bearer] = dueAdminBearer();
        $soon = dueMachine(['next_maintenance_due_at' => now()->addDays(7)->toDateString()]);
        dueMachine(['next_maintenance_due_at' => now()->addDays(90)->toDateString()]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1);
        expect($response->json('data.0.id'))->toBe($soon->id);
    });

    it('returns 200 with a broader set when ?days=60', function (): void {
        [, $bearer] = dueAdminBearer();
        dueMachine(['next_maintenance_due_at' => now()->addDays(7)->toDateString()]);
        dueMachine(['next_maintenance_due_at' => now()->addDays(45)->toDateString()]);
        dueMachine(['next_maintenance_due_at' => now()->addDays(120)->toDateString()]);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance?days=60')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    });

    it('returns 422 for ?days=400 (max 365)', function (): void {
        [, $bearer] = dueAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance?days=400')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['days']]);
    });

    it('returns 422 for ?days=0 (min 1)', function (): void {
        [, $bearer] = dueAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance?days=0')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['days']]);
    });

    it('returns 403 ERR_FORBIDDEN for support role (no machines.view)', function (): void {
        [, $bearer] = dueAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/machines/due-maintenance')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 200 with empty data when nothing is due', function (): void {
        [, $bearer] = dueAdminBearer();
        dueMachine(['next_maintenance_due_at' => now()->addDays(120)->toDateString()]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/machines/due-maintenance')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(0);
        expect($response->json('data'))->toBe([]);
    });
});
