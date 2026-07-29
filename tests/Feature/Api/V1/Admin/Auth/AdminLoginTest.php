<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/auth/login
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/admin/auth/login (happy path)', function (): void {
    it('returns 200 with the admin envelope (user + token + role + permissions + capabilities) for super_admin', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'admin@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@pwt.local',
            'password' => 'Secret1234!',
        ]);

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
                    'token' => ['access_token', 'token_type', 'expires_at', 'abilities'],
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
            ->and($response->json('data.user.email'))->toBe('admin@pwt.local')
            ->and($response->json('data.role'))->toBe('super_admin')
            ->and($response->json('data.token.token_type'))->toBe('Bearer')
            ->and($response->json('data.token.abilities'))->toBe(['admin'])
            ->and($response->json('data.permissions'))->toBeArray()
            ->and($response->json('data.permissions'))->not->toBeEmpty();

        // capabilities map (super_admin gets everything true)
        expect($response->json('data.capabilities.orders.view'))->toBeTrue()
            ->and($response->json('data.capabilities.orders.cancel'))->toBeTrue()
            ->and($response->json('data.capabilities.users.create'))->toBeTrue()
            ->and($response->json('data.capabilities.audit_logs.view'))->toBeTrue();
    });

    it('issues a token with the admin ability and ~8h expiry', function (): void {
        Carbon::setTestNow('2026-05-15 09:00:00');

        User::factory()->admin(UserRole::Operations->value)->create([
            'email' => 'ops@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@pwt.local',
            'password' => 'Secret1234!',
        ])->assertOk();

        $tokenString = (string) $response->json('data.token.access_token');
        [$tokenId] = explode('|', $tokenString, 2);

        /** @var PersonalAccessToken|null $token */
        $token = PersonalAccessToken::query()->find((int) $tokenId);

        expect($token)->not->toBeNull()
            ->and($token->name)->toBe('admin')
            ->and($token->abilities)->toBe(['admin'])
            ->and($token->expires_at)->not->toBeNull();

        // ~8 hours after "now" (the configured admin token TTL).
        $expected = Carbon::parse('2026-05-15 09:00:00')->addHours(8);
        expect($token->expires_at->equalTo($expected))->toBeTrue();

        // The response's `expires_at` mirrors the token row.
        expect($response->json('data.token.expires_at'))->toBe($expected->toIso8601String());
    });

    it('updates last_login_at on the users row when login succeeds', function (): void {
        Carbon::setTestNow('2026-05-15 09:00:00');

        $admin = User::factory()->admin(UserRole::Sales->value)->create([
            'email' => 'sales@pwt.local',
            'password' => Hash::make('Secret1234!'),
            'last_login_at' => null,
        ]);

        expect($admin->last_login_at)->toBeNull();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'sales@pwt.local',
            'password' => 'Secret1234!',
        ])->assertOk();

        $admin->refresh();
        expect($admin->last_login_at)->not->toBeNull()
            ->and($admin->last_login_at->equalTo(Carbon::parse('2026-05-15 09:00:00')))->toBeTrue();
    });

    it('writes an activity_log entry on successful admin login', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create([
            'email' => 'ops@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ops@pwt.local',
            'password' => 'Secret1234!',
        ])->assertOk();

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'admin.login')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->description)->toBe('Admin logged in')
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($admin->id)
            ->and($entry->properties['role'] ?? null)->toBe('operations');
    });

    it('lowercases the submitted email in prepareForValidation so admin@PWT.local logs in', function (): void {
        User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'admin@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@PWT.local',
            'password' => 'Secret1234!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'admin@pwt.local');
    });

    it('lets each of the four admin roles log in successfully', function (string $role): void {
        User::factory()->admin($role)->create([
            'email' => $role.'@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $role.'@pwt.local',
            'password' => 'Secret1234!',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role', $role)
            ->assertJsonPath('data.token.abilities', ['admin']);
    })->with([
        'super_admin' => 'super_admin',
        'operations' => 'operations',
        'sales' => 'sales',
        'support' => 'support',
    ]);
});

describe('POST /api/v1/admin/auth/login (anti-enumeration 401 contract)', function (): void {
    it('returns 401 ERR_INVALID_CREDENTIALS when a customer tries with their CORRECT password', function (): void {
        // § 5.3 hard contract — a customer hitting the admin login must
        // get the same 401 as an unknown email, not 403 and not 200.
        User::factory()->customer()->create([
            'email' => 'customer@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'customer@pwt.local',
            'password' => 'Secret1234!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS when the password is wrong', function (): void {
        User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'admin@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@pwt.local',
            'password' => 'WrongPass1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS when the email is unknown', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'nobody@pwt.local',
            'password' => 'Secret1234!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns the IDENTICAL 401 envelope for wrong password and customer credentials (no enumeration)', function (): void {
        User::factory()->customer()->create([
            'email' => 'customer@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);
        User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'admin@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $customerAttempt = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'customer@pwt.local',
            'password' => 'Secret1234!',
        ]);

        $wrongPassword = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@pwt.local',
            'password' => 'WrongPass1!',
        ]);

        // Strip the dynamic plain text token in case anything leaks (it shouldn't on errors).
        expect($customerAttempt->status())->toBe($wrongPassword->status())
            ->and($customerAttempt->json('code'))->toBe($wrongPassword->json('code'))
            ->and($customerAttempt->json('message'))->toBe($wrongPassword->json('message'))
            ->and($customerAttempt->json('success'))->toBe($wrongPassword->json('success'));
    });
});

describe('POST /api/v1/admin/auth/login (inactive admin)', function (): void {
    it('returns 403 ERR_ADMIN_INACTIVE when an admin with the correct password is deactivated', function (): void {
        User::factory()->admin(UserRole::Operations->value)->inactive()->create([
            'email' => 'inactive@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@pwt.local',
            'password' => 'Secret1234!',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_ADMIN_INACTIVE');
    });
});

describe('POST /api/v1/admin/auth/login (validation)', function (): void {
    it('returns 422 ERR_VALIDATION when email is missing', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'password' => 'Secret1234!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['success', 'message', 'errors' => ['email']]);
    });

    it('returns 422 ERR_VALIDATION when password is missing', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@pwt.local',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['success', 'message', 'errors' => ['password']]);
    });

    it('returns 422 ERR_VALIDATION when password is shorter than 8 characters', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@pwt.local',
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['password']]);
    });

    it('returns 422 ERR_VALIDATION when email is malformed', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'not-an-email',
            'password' => 'Secret1234!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['email']]);
    });
});

describe('POST /api/v1/admin/auth/login (rate limit)', function (): void {
    it('returns 429 ERR_RATE_LIMITED after the 6th attempt in one minute from the same IP+email', function (): void {
        User::factory()->admin(UserRole::SuperAdmin->value)->create([
            'email' => 'ratelimit@pwt.local',
            'password' => Hash::make('Secret1234!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => 'ratelimit@pwt.local',
                'password' => 'wrong',
            ]);
        }

        $sixth = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ratelimit@pwt.local',
            'password' => 'wrong',
        ]);

        $sixth->assertStatus(429)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });
});
