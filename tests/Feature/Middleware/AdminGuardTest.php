<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

describe('AdminGuard middleware on admin endpoints', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no Bearer token is supplied', function (): void {
        $response = $this->getJson('/api/v1/admin/me');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 403 ERR_FORBIDDEN when a customer token hits an admin endpoint', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_ADMIN_INACTIVE when a deactivated admin token hits an admin endpoint', function (): void {
        // Token is minted BEFORE deactivation so it's a valid Sanctum
        // bearer, simulating a fired employee whose token has not been
        // revoked. AdminGuard must reject on the is_active check.
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $admin->forceFill(['is_active' => false])->save();

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_ADMIN_INACTIVE');
    });

    it('lets a valid super_admin token through to the admin endpoint', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertOk()
            ->assertJsonPath('data.role', 'super_admin');
    });

    it('lets each of the four admin roles through to the admin endpoint', function (string $role): void {
        $admin = User::factory()->admin($role)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertOk()
            ->assertJsonPath('data.role', $role);
    })->with([
        'super_admin' => 'super_admin',
        'operations' => 'operations',
        'sales' => 'sales',
        'support' => 'support',
    ]);

    it('also gates the admin logout endpoint against customer tokens', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->postJson('/api/v1/admin/auth/logout');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
