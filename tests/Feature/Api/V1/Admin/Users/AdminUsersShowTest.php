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

describe('GET /api/v1/admin/users/{id}', function (): void {
    it('returns 200 with the full detail envelope for an owner (super_admin)', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/users/{$target->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'name', 'email', 'phone', 'phone_country_code',
                    'phone_e164', 'locale', 'is_active', 'role',
                    'last_login_at', 'created_at', 'deleted_at',
                    'counts' => ['orders_count', 'support_tickets_count', 'machines_count'],
                ],
            ]);

        expect($response->json('data.id'))->toBe($target->id)
            ->and($response->json('data.role'))->toBe('customer')
            ->and($response->json('data.deleted_at'))->toBeNull()
            ->and($response->json('data.counts.orders_count'))->toBe(0)
            ->and($response->json('data.counts.support_tickets_count'))->toBe(0)
            ->and($response->json('data.counts.machines_count'))->toBe(0);
    });

    it('returns 200 for a soft-deleted target when called by super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $target = User::factory()->customer()->create();
        $target->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/users/{$target->id}");

        $response->assertOk();
        expect($response->json('data.id'))->toBe($target->id)
            ->and($response->json('data.deleted_at'))->not->toBeNull();
    });

    it('returns 200 for a support-role admin reading a customer detail', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id);
    });

    it('returns 403 for an operations-role admin (no users.view)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;
        $target = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $bearer = 'Bearer '.$admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/users/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED when no Bearer token is sent', function (): void {
        $target = User::factory()->customer()->create();

        $this->getJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
