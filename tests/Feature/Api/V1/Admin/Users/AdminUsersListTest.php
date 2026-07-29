<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Create an admin user + token in a single helper. Returns the bearer
 * value (already prefixed with "Bearer ").
 */
function adminBearer(string $role = UserRole::SuperAdmin->value, array $overrides = []): array
{
    $admin = User::factory()->admin($role)->create($overrides);
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/users (happy path)', function (): void {
    it('returns 200 with paginated list of all users and counts for super_admin', function (): void {
        [$admin, $bearer] = adminBearer();
        User::factory()->count(3)->customer()->create();
        User::factory()->admin(UserRole::Support->value)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'name', 'email', 'phone', 'phone_country_code',
                        'phone_e164', 'locale', 'is_active', 'role',
                        'last_login_at', 'created_at', 'deleted_at',
                        'counts' => ['orders_count', 'support_tickets_count', 'machines_count'],
                    ],
                ],
                'meta' => [
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                ],
            ]);

        // The caller admin + 3 customers + 1 support admin = 5 rows total.
        expect($response->json('meta.pagination.total'))->toBe(5);
        // Counts attribute populated as integers.
        expect($response->json('data.0.counts.orders_count'))->toBeInt();
    });

    it('filters by role=customer', function (): void {
        [, $bearer] = adminBearer();
        User::factory()->count(3)->customer()->create();
        User::factory()->admin(UserRole::Sales->value)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?role=customer');

        $response->assertOk();

        $roles = array_unique(array_map(static fn ($r) => $r['role'], $response->json('data')));
        expect($roles)->toBe(['customer']);
        expect($response->json('meta.pagination.total'))->toBe(3);
    });

    it('filters by is_active=false', function (): void {
        [, $bearer] = adminBearer();
        User::factory()->count(2)->customer()->create();
        User::factory()->count(2)->customer()->inactive()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?is_active=0');

        $response->assertOk();
        $active = array_unique(array_map(static fn ($r) => $r['is_active'], $response->json('data')));
        expect($active)->toBe([false]);
        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('filters by search substring matching email', function (): void {
        [, $bearer] = adminBearer();
        User::factory()->customer()->create(['email' => 'needle@example.com', 'name' => 'Hidden Name']);
        User::factory()->customer()->create(['email' => 'noise@example.com']);
        User::factory()->customer()->create(['email' => 'other@example.com']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?search=needle');

        $response->assertOk();
        expect($response->json('meta.pagination.total'))->toBe(1);
        expect($response->json('data.0.email'))->toBe('needle@example.com');
    });

    it('filters by created_from and created_to', function (): void {
        [, $bearer] = adminBearer();

        Carbon::setTestNow('2026-04-01 12:00:00');
        $old = User::factory()->customer()->create();

        Carbon::setTestNow('2026-05-10 12:00:00');
        $inWindow = User::factory()->customer()->create();

        Carbon::setTestNow('2026-06-15 12:00:00');
        $future = User::factory()->customer()->create();

        Carbon::setTestNow();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?created_from=2026-05-01&created_to=2026-05-31');

        $response->assertOk();
        $ids = array_map(static fn ($r) => $r['id'], $response->json('data'));
        expect($ids)->toContain($inWindow->id);
        expect($ids)->not->toContain($old->id);
        expect($ids)->not->toContain($future->id);
    });

    it('includes soft-deleted rows when include_trashed=1 for super_admin', function (): void {
        [, $bearer] = adminBearer();
        $deleted = User::factory()->customer()->create();
        $deleted->delete();
        User::factory()->customer()->create(); // alive

        $without = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users')
            ->assertOk();
        $ids = array_map(static fn ($r) => $r['id'], $without->json('data'));
        expect($ids)->not->toContain($deleted->id);

        $with = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?include_trashed=1')
            ->assertOk();
        $ids = array_map(static fn ($r) => $r['id'], $with->json('data'));
        expect($ids)->toContain($deleted->id);

        // The deleted_at field is populated for that row.
        $row = collect($with->json('data'))->firstWhere('id', $deleted->id);
        expect($row['deleted_at'])->not->toBeNull();
    });

    it('honours sort=name order=asc', function (): void {
        [, $bearer] = adminBearer(UserRole::SuperAdmin->value, ['name' => 'Zeta Admin']);
        User::factory()->customer()->create(['name' => 'Alpha Customer']);
        User::factory()->customer()->create(['name' => 'Mike Customer']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?sort=name&order=asc')
            ->assertOk();

        $names = array_map(static fn ($r) => $r['name'], $response->json('data'));
        $sorted = $names;
        sort($sorted);
        expect($names)->toBe($sorted);
    });

    it('returns 422 when per_page exceeds the 100 maximum', function (): void {
        [, $bearer] = adminBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?per_page=999');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('allows a support-role admin to view the list (200)', function (): void {
        [, $bearer] = adminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    });

    it('returns 403 for operations role (no users.view permission)', function (): void {
        [, $bearer] = adminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for sales role (no users.view permission)', function (): void {
        [, $bearer] = adminBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN when a customer token is used', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 when no Bearer token is sent', function (): void {
        $this->getJson('/api/v1/admin/users')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns Arabic envelope messages when Accept-Language=ar', function (): void {
        [, $bearer] = adminBearer();

        $response = $this->withHeaders([
            'Authorization' => $bearer,
            'Accept-Language' => 'ar',
        ])->getJson('/api/v1/admin/users');

        $response->assertOk();
        expect($response->json('message'))->toBe(__('messages.ok', [], 'ar'));
    });

    it('returns 403 when a non-super_admin tries include_trashed=1', function (): void {
        [, $bearer] = adminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users?include_trashed=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
