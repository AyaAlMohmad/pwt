<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\Auth\AdminInactiveException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| AdminAuthService::login
|--------------------------------------------------------------------------
*/

describe('AdminAuthService::login', function (): void {
    it('returns the user + a NewAccessToken with the admin ability on the happy path', function (): void {
        Carbon::setTestNow('2026-05-15 09:00:00');

        $admin = User::factory()->admin(UserRole::Operations->value)->create([
            'email' => 'ops@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $service = app(AdminAuthService::class);
        $result = $service->login('ops@pwt.local', 'Secret1234!', '127.0.0.1');

        expect($result)->toBeArray()
            ->and($result)->toHaveKeys(['user', 'token'])
            ->and($result['user'])->toBeInstanceOf(User::class)
            ->and($result['user']->id)->toBe($admin->id)
            ->and($result['token'])->toBeInstanceOf(NewAccessToken::class);

        /** @var NewAccessToken $token */
        $token = $result['token'];
        expect($token->accessToken->name)->toBe('admin')
            ->and($token->accessToken->abilities)->toBe(['admin'])
            ->and($token->accessToken->expires_at)->not->toBeNull()
            ->and($token->accessToken->expires_at->equalTo(Carbon::parse('2026-05-15 17:00:00')))->toBeTrue();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->getKey(),
            'name' => 'admin',
            'tokenable_id' => $admin->id,
        ]);
    });

    it('sets last_login_at on the admin row on successful login', function (): void {
        Carbon::setTestNow('2026-05-15 10:00:00');

        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'super@pwt.local',
            'password' => Hash::make('Secret1234!'),
            'last_login_at' => null,
        ]);

        app(AdminAuthService::class)->login('super@pwt.local', 'Secret1234!');

        $admin->refresh();
        expect($admin->last_login_at)->not->toBeNull()
            ->and($admin->last_login_at->equalTo(Carbon::parse('2026-05-15 10:00:00')))->toBeTrue();
    });

    it('throws InvalidCredentialsException when the password is wrong', function (): void {
        User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'admin@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        app(AdminAuthService::class)->login('admin@pwt.local', 'wrong-password');
    })->throws(InvalidCredentialsException::class);

    it('throws InvalidCredentialsException — not AdminInactiveException — when a customer attempts to log in with the correct password', function (): void {
        User::factory()->customer()->create([
            'email' => 'customer@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        try {
            app(AdminAuthService::class)->login('customer@pwt.local', 'Secret1234!');
            $this->fail('Expected InvalidCredentialsException to be thrown.');
        } catch (AdminInactiveException) {
            $this->fail('Expected InvalidCredentialsException, got AdminInactiveException (enumeration leak).');
        } catch (InvalidCredentialsException $e) {
            expect($e->status)->toBe(401)
                ->and($e->errorCode)->toBe('ERR_INVALID_CREDENTIALS');
        }
    });

    it('throws InvalidCredentialsException for an unknown email', function (): void {
        app(AdminAuthService::class)->login('nobody@pwt.local', 'Secret1234!');
    })->throws(InvalidCredentialsException::class);

    it('throws AdminInactiveException when an admin with valid creds has is_active=false', function (): void {
        User::factory()->admin(UserRole::Operations->value)->inactive()->create([
            'email' => 'inactive@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        try {
            app(AdminAuthService::class)->login('inactive@pwt.local', 'Secret1234!');
            $this->fail('Expected AdminInactiveException to be thrown.');
        } catch (AdminInactiveException $e) {
            expect($e->status)->toBe(403)
                ->and($e->errorCode)->toBe('ERR_ADMIN_INACTIVE');
        }
    });
});

/*
|--------------------------------------------------------------------------
| AdminAuthService::logout
|--------------------------------------------------------------------------
*/

describe('AdminAuthService::logout', function (): void {
    it('revokes the current Sanctum access token', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $new = $admin->createToken('admin', ['admin']);
        $tokenId = $new->accessToken->getKey();

        // Simulate Sanctum attaching the current access token (as
        // happens after auth:sanctum middleware on a real request).
        /** @var PersonalAccessToken $current */
        $current = PersonalAccessToken::query()->findOrFail($tokenId);
        $admin->withAccessToken($current);

        app(AdminAuthService::class)->logout($admin);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        expect($admin->tokens()->count())->toBe(0);
    });

    it('throws AuthenticationException when no current token is attached', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();

        app(AdminAuthService::class)->logout($admin);
    })->throws(AuthenticationException::class);
});

/*
|--------------------------------------------------------------------------
| AdminAuthService::resolveAdminRole
|--------------------------------------------------------------------------
*/

describe('AdminAuthService::resolveAdminRole', function (): void {
    it('returns the single role for an admin holding exactly one admin role', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();

        expect(app(AdminAuthService::class)->resolveAdminRole($admin))->toBe('sales');
    });

    it('picks the highest-privilege role when an admin holds more than one (data inconsistency)', function (): void {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Support->value);
        $admin->assignRole(UserRole::SuperAdmin->value);

        expect(app(AdminAuthService::class)->resolveAdminRole($admin))->toBe('super_admin');
    });

    it('returns null for a customer (no admin role present)', function (): void {
        $customer = User::factory()->customer()->create();

        expect(app(AdminAuthService::class)->resolveAdminRole($customer))->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| AdminAuthService::buildCapabilities
|--------------------------------------------------------------------------
*/

describe('AdminAuthService::buildCapabilities', function (): void {
    it('returns a fixed-shape map with all 13 domains regardless of role', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $admin->load('roles.permissions');

        $caps = app(AdminAuthService::class)->buildCapabilities($admin);

        expect($caps)->toBeArray()
            ->and(array_keys($caps))->toEqualCanonicalizing([
                'users', 'products', 'categories', 'orders', 'maintenance',
                'machines', 'support', 'promotions', 'banners', 'testimonials',
                'reports', 'audit_logs', 'notifications',
            ]);
    });

    it('returns every action as a boolean (no nulls, no strings)', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $admin->load('roles.permissions');

        $caps = app(AdminAuthService::class)->buildCapabilities($admin);

        foreach ($caps as $domain => $actions) {
            foreach ($actions as $action => $value) {
                expect($value)->toBeBool("$domain.$action is not boolean");
            }
        }
    });

    it('converts hyphenated permission keys to snake_case action keys (products.manage-images → manage_images)', function (): void {
        $admin = User::factory()->admin(UserRole::Sales->value)->create();
        $admin->load('roles.permissions');

        $caps = app(AdminAuthService::class)->buildCapabilities($admin);

        expect($caps['products'])->toHaveKey('manage_images')
            ->and($caps['products']['manage_images'])->toBeTrue();

        // And the corresponding hyphenated form does NOT leak through.
        expect($caps['products'])->not->toHaveKey('manage-images');
    });

    it('converts orders.update-status into orders.update_status', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $admin->load('roles.permissions');

        $caps = app(AdminAuthService::class)->buildCapabilities($admin);

        expect($caps['orders'])->toHaveKey('update_status')
            ->and($caps['orders']['update_status'])->toBeTrue()
            ->and($caps['orders'])->toHaveKey('cancel_any')
            ->and($caps['orders']['cancel_any'])->toBeTrue();
    });

    it('reflects the role assignment — super_admin sees everything true, support sees its narrow bucket', function (): void {
        $super = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $support = User::factory()->admin(UserRole::Support->value)->create();
        $super->load('roles.permissions');
        $support->load('roles.permissions');

        $service = app(AdminAuthService::class);

        $superCaps = $service->buildCapabilities($super);
        $supportCaps = $service->buildCapabilities($support);

        // super_admin has at least one true in every domain.
        foreach ($superCaps as $domain => $actions) {
            expect(in_array(true, $actions, true))
                ->toBeTrue("super_admin should have at least one granted action in $domain");
        }

        // support gets its bucket + read-only on orders/maintenance/users.
        expect($supportCaps['support']['view'])->toBeTrue()
            ->and($supportCaps['support']['reply'])->toBeTrue()
            ->and($supportCaps['orders']['view'])->toBeTrue()
            ->and($supportCaps['users']['view'])->toBeTrue()
            ->and($supportCaps['products']['create'])->toBeFalse()
            ->and($supportCaps['audit_logs']['view'])->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| AdminAuthService::permissionKeys
|--------------------------------------------------------------------------
*/

describe('AdminAuthService::permissionKeys', function (): void {
    it('returns a flat, unique, sorted list of permission key strings', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $admin->load('roles.permissions');

        $keys = app(AdminAuthService::class)->permissionKeys($admin);

        expect($keys)->toBeArray()
            ->and($keys)->not->toBeEmpty();

        // sorted ascending
        $sorted = $keys;
        sort($sorted);
        expect($keys)->toBe($sorted);

        // unique
        expect(array_values(array_unique($keys)))->toBe($keys);

        // all strings
        foreach ($keys as $k) {
            expect($k)->toBeString();
        }

        // operations has orders / maintenance / machines + support.view
        expect($keys)->toContain('orders.view')
            ->and($keys)->toContain('maintenance.view')
            ->and($keys)->toContain('machines.view')
            ->and($keys)->toContain('support.view')
            ->and($keys)->not->toContain('products.create');
    });
});
