<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/audit-logs
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-audit-templates-dashboard.md
| § 4.1 / § 6.2.1 / § 6.4 / § 9.9 / § 9.11.
|
| super_admin only — operations / sales / support → 403.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{0: User, 1: string}
 */
function auditBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function seedActivity(array $overrides = []): Activity
{
    $defaults = [
        'log_name' => 'admin.orders',
        'description' => 'order.status.updated',
        'event' => 'updated',
        'subject_type' => Order::class,
        'subject_id' => 1,
        'causer_type' => User::class,
        'causer_id' => null,
        'properties' => ['old' => ['status' => 'pending'], 'new' => ['status' => 'confirmed']],
    ];

    /** @var Activity $activity */
    $activity = Activity::query()->forceCreate(array_merge($defaults, $overrides));

    return $activity;
}

describe('GET /api/v1/admin/audit-logs — authorization', function (): void {
    it('returns 200 for super_admin with paginated entries', function (): void {
        [$admin, $bearer] = auditBearer(UserRole::SuperAdmin->value);
        seedActivity(['causer_id' => $admin->id]);
        seedActivity(['causer_id' => $admin->id, 'log_name' => 'admin.machines']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'log_name',
                        'description',
                        'event',
                        'subject_type',
                        'subject_id',
                        'causer' => ['id', 'name', 'role'],
                        'properties',
                        'created_at',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);

        expect($response->json('success'))->toBeTrue()
            ->and(count($response->json('data')))->toBeGreaterThanOrEqual(2);
    });

    it('returns 403 for operations', function (): void {
        [, $bearer] = auditBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for sales', function (): void {
        [, $bearer] = auditBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for support', function (): void {
        [, $bearer] = auditBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for a customer token (admin middleware)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/audit-logs')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a token', function (): void {
        $this->getJson('/api/v1/admin/audit-logs')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/audit-logs — filters', function (): void {
    it('filters by actor_id', function (): void {
        [$admin, $bearer] = auditBearer();
        $other = User::factory()->admin(UserRole::Operations->value)->create();
        seedActivity(['causer_id' => $admin->id]);
        seedActivity(['causer_id' => $other->id]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?actor_id='.$other->id)
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.causer.id'))->toBe($other->id)
            ->and($response->json('data.0.causer.role'))->toBe('operations');
    });

    it('filters by action (alias for log_name)', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'log_name' => 'admin.orders']);
        seedActivity(['causer_id' => $admin->id, 'log_name' => 'admin.machines']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?action=admin.machines')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.log_name'))->toBe('admin.machines');
    });

    it('filters by log_name', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'log_name' => 'admin.orders']);
        seedActivity(['causer_id' => $admin->id, 'log_name' => 'report.export']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?log_name=report.export')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.log_name'))->toBe('report.export');
    });

    it('filters by event', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'event' => 'created']);
        seedActivity(['causer_id' => $admin->id, 'event' => 'deleted']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?event=deleted')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.event'))->toBe('deleted');
    });

    it('filters by subject_type FQN', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'subject_type' => Order::class, 'subject_id' => 7]);
        seedActivity(['causer_id' => $admin->id, 'subject_type' => User::class, 'subject_id' => 7]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?subject_type='.urlencode(Order::class))
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.subject_type'))->toBe(Order::class);
    });

    it('filters by subject_type short alias', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'subject_type' => Order::class, 'subject_id' => 1]);
        seedActivity(['causer_id' => $admin->id, 'subject_type' => User::class, 'subject_id' => 1]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?subject_type=order')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.subject_type'))->toBe(Order::class);
    });

    it('filters by subject_id', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'subject_id' => 42]);
        seedActivity(['causer_id' => $admin->id, 'subject_id' => 99]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?subject_id=42')
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.subject_id'))->toBe(42);
    });

    it('filters by date_from / date_to range', function (): void {
        [$admin, $bearer] = auditBearer();
        seedActivity(['causer_id' => $admin->id, 'created_at' => now()->subDays(30)]);
        $inRange = seedActivity(['causer_id' => $admin->id, 'created_at' => now()->subDay()]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/audit-logs?date_from=$from&date_to=$to")
            ->assertOk();

        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.id'))->toBe($inRange->id);
    });

    it('rejects a date_to earlier than date_from with 422', function (): void {
        [, $bearer] = auditBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?date_from='.now()->toDateString().'&date_to='.now()->subDays(10)->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['date_to']]);
    });

    it('returns 422 for unknown actor_id (exists rule)', function (): void {
        [, $bearer] = auditBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?actor_id=999999')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['actor_id']]);
    });
});

describe('GET /api/v1/admin/audit-logs — pagination', function (): void {
    it('defaults to 50 rows per page', function (): void {
        [$admin, $bearer] = auditBearer();
        for ($i = 0; $i < 3; $i++) {
            seedActivity(['causer_id' => $admin->id]);
        }

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk();

        expect($response->json('meta.pagination.per_page'))->toBe(50);
    });

    it('accepts per_page=200 (the documented max)', function (): void {
        [, $bearer] = auditBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?per_page=200')
            ->assertOk();

        expect($response->json('meta.pagination.per_page'))->toBe(200);
    });

    it('rejects per_page=201 with 422', function (): void {
        [, $bearer] = auditBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?per_page=201')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['per_page']]);
    });
});

describe('GET /api/v1/admin/audit-logs — causer block shape', function (): void {
    it('exposes { id, name, role } for an admin causer', function (): void {
        [, $bearer] = auditBearer();
        $actor = User::factory()->admin(UserRole::Operations->value)->create([
            'name' => 'Layla Ops',
        ]);
        seedActivity(['causer_id' => $actor->id, 'log_name' => 'admin.orders']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?actor_id='.$actor->id)
            ->assertOk();

        $causer = $response->json('data.0.causer');
        expect($causer)->toHaveKeys(['id', 'name', 'role'])
            ->and($causer['id'])->toBe($actor->id)
            ->and($causer['name'])->toBe('Layla Ops')
            ->and($causer['role'])->toBe(UserRole::Operations->value);
    });

    it('preserves causer fields when the actor is soft-deleted', function (): void {
        [, $bearer] = auditBearer();
        $actor = User::factory()->admin(UserRole::Sales->value)->create([
            'name' => 'Ex Sales',
        ]);
        seedActivity(['causer_id' => $actor->id]);
        $actor->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/audit-logs?actor_id='.$actor->id)
            ->assertOk();

        expect($response->json('data.0.causer.name'))->toBe('Ex Sales')
            ->and($response->json('data.0.causer.role'))->toBe(UserRole::Sales->value);
    });
});
