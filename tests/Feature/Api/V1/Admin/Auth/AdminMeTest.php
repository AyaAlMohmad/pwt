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

describe('GET /api/v1/admin/me', function (): void {
    it('returns 200 with user + role + permissions + capabilities for an authenticated super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => [
                        'id', 'name', 'email', 'phone', 'phone_country_code',
                        'role', 'locale', 'last_login_at', 'created_at',
                    ],
                    'role',
                    'permissions',
                    'capabilities' => [
                        'users', 'products', 'categories', 'orders', 'maintenance',
                        'machines', 'support', 'promotions', 'banners', 'testimonials',
                        'reports', 'audit_logs', 'notifications',
                    ],
                ],
            ]);

        expect($response->json('data.user.id'))->toBe($admin->id)
            ->and($response->json('data.role'))->toBe('super_admin');
    });

    it('does NOT return a token block on /me', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        expect($response->json('data.token'))->toBeNull()
            ->and(array_key_exists('token', (array) $response->json('data')))->toBeFalse();
    });

    it('does NOT update last_login_at when calling /me', function (): void {
        Carbon::setTestNow('2026-05-15 09:00:00');

        $admin = User::factory()->admin(UserRole::Operations->value)->create([
            'last_login_at' => null,
        ]);
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        $admin->refresh();
        expect($admin->last_login_at)->toBeNull();
    });

    it('exposes the super_admin capability map with every domain action set to true', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        // Spot-check core admin buckets — every action should be granted.
        foreach (['orders.view', 'orders.cancel', 'orders.refund',
            'users.create', 'audit_logs.view', 'reports.export',
            'products.create', 'categories.delete',
        ] as $path) {
            expect($response->json('data.capabilities.'.$path))->toBeTrue();
        }
    });

    it('returns the sales-role capability map with catalog/promo/banners/testimonials granted and ops buckets false', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        expect($response->json('data.role'))->toBe('sales');

        // Sales has full catalog / promo / banners / testimonials.
        foreach (['products.view', 'products.create', 'products.update', 'products.deactivate', 'products.manage_images',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
            'banners.view', 'banners.create', 'banners.update', 'banners.delete',
            'testimonials.view', 'testimonials.create', 'testimonials.update', 'testimonials.delete',
        ] as $path) {
            expect($response->json('data.capabilities.'.$path))->toBeTrue();
        }

        // Phase 21 — Sales receives the reports bucket (per-type access is
        // gate-side, not capability-key-side).
        expect($response->json('data.capabilities.reports.view'))->toBeTrue();
        expect($response->json('data.capabilities.reports.export'))->toBeTrue();

        // Sales does NOT manage orders / maintenance / users / audit logs.
        foreach (['orders.view', 'orders.cancel', 'orders.refund',
            'maintenance.view', 'maintenance.create',
            'users.view', 'users.create',
            'audit_logs.view',
            'notifications.view', 'machines.view',
        ] as $path) {
            expect($response->json('data.capabilities.'.$path))->toBeFalse();
        }
    });

    it('returns the operations-role capability map with orders/maintenance/machines granted', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        expect($response->json('data.role'))->toBe('operations');

        foreach (['orders.view', 'orders.update_status', 'orders.cancel',
            'maintenance.view', 'maintenance.create', 'maintenance.close',
            'machines.view', 'machines.create',
        ] as $path) {
            expect($response->json('data.capabilities.'.$path))->toBeTrue();
        }

        // Operations cannot edit catalog or read audit logs.
        foreach (['products.create', 'categories.create',
            'audit_logs.view', 'users.create',
        ] as $path) {
            expect($response->json('data.capabilities.'.$path))->toBeFalse();
        }
    });

    it('returns the support-role capability map', function (): void {
        $admin = User::factory()->admin(UserRole::Support->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me')
            ->assertOk();

        expect($response->json('data.role'))->toBe('support');

        // Support has the support bucket + read-only on orders/maintenance/users.
        expect($response->json('data.capabilities.support.view'))->toBeTrue()
            ->and($response->json('data.capabilities.support.reply'))->toBeTrue()
            ->and($response->json('data.capabilities.orders.view'))->toBeTrue()
            ->and($response->json('data.capabilities.users.view'))->toBeTrue()
            ->and($response->json('data.capabilities.orders.cancel'))->toBeFalse()
            ->and($response->json('data.capabilities.products.create'))->toBeFalse();
    });

    it('returns 401 ERR_UNAUTHENTICATED with no Bearer token', function (): void {
        $response = $this->getJson('/api/v1/admin/me');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 403 ERR_FORBIDDEN when a customer token is used', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->getJson('/api/v1/admin/me');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
