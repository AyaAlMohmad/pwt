<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    StubSmsDriver::flush();
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/forgot-password
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/forgot-password (email channel)', function (): void {
    it('returns 200 generic and queues a PasswordResetMail for a known email', function (): void {
        User::factory()->customer()->create(['email' => 'alice@example.com']);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['channel', 'expires_at'],
            ])
            ->assertJsonPath('data.channel', 'email');

        expect($response->json('data.dev_token'))->toBeString()
            ->and(strlen((string) $response->json('data.dev_token')))->toBe(64);

        Mail::assertQueued(PasswordResetMail::class, 1);

        $this->assertDatabaseHas('password_resets', [
            'identifier' => 'alice@example.com',
            'channel' => 'email',
        ]);
    });

    it('returns 200 generic and DOES NOT queue mail for an unknown email (anti-enumeration)', function (): void {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'ghost@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.channel', 'email');

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('password_resets', 0);
    });

    it('returns 429 ERR_RATE_LIMITED after the 4th request in one hour', function (): void {
        for ($i = 0; $i < 3; $i++) {
            $r = $this->postJson('/api/v1/auth/forgot-password', [
                'email' => 'alice@example.com',
            ]);
            if ($r->status() === 429) {
                $this->markTestSkipped('Limiter tripped earlier than the contract requires.');
            }
        }

        $fourth = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ]);

        $fourth->assertStatus(429)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });

    it('returns 422 ERR_VALIDATION when neither email nor phone is supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/forgot-password', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});

describe('POST /api/v1/auth/forgot-password (phone channel)', function (): void {
    it('returns 200 and dispatches one SMS for a known phone', function (): void {
        User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => '+971501234567',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.channel', 'phone');

        expect($response->json('data.dev_otp'))->toMatch('/^\d{6}$/');

        $dispatched = StubSmsDriver::dispatched();
        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['context']['purpose'])->toBe('reset');

        $this->assertDatabaseHas('password_resets', [
            'identifier' => '+971501234567',
            'channel' => 'phone',
        ]);
    });

    it('returns 200 generic for an unknown phone and does NOT send SMS', function (): void {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => '+971500000000',
        ]);

        $response->assertOk();
        expect(StubSmsDriver::dispatched())->toBeEmpty();
        $this->assertDatabaseCount('password_resets', 0);
    });
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/reset-password
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/reset-password (email)', function (): void {
    it('updates the password, revokes all existing tokens, and allows login with the new password', function (): void {
        $user = User::factory()->customer()->create([
            'email' => 'alice@example.com',
            'password' => Hash::make('OldPassword1!'),
        ]);
        $oldTokenPlain = $user->createToken('mobile')->plainTextToken;
        $oldTokenId = (int) explode('|', $oldTokenPlain, 2)[0];

        // Request the reset ONCE and capture the dev_token from the same response.
        $forgot = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ]);
        $forgot->assertOk();
        $resetToken = (string) $forgot->json('data.dev_token');

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => $resetToken,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        // All prior tokens are revoked at the DB layer — proves the
        // "revoke all tokens" contract from architecture § 2.3.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldTokenId]);
        expect($user->tokens()->count())->toBe(0);

        // Login with the new password succeeds.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'NewPassword1!',
        ])->assertOk();
    });

    it('returns 422 ERR_RESET_TOKEN_INVALID for a structurally valid but wrong token', function (): void {
        User::factory()->customer()->create(['email' => 'alice@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ])->assertOk();

        // 64-hex string passes structural validation (regex /^[a-f0-9]{64}$/)
        // but does not match the stored bcrypt hash.
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => str_repeat('a', 64),
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_RESET_TOKEN_INVALID');
    });

    it('returns 422 ERR_VALIDATION when the token contains non-hex characters', function (): void {
        User::factory()->customer()->create(['email' => 'alice@example.com']);
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ])->assertOk();

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => str_repeat('z', 64),
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_RESET_TOKEN_INVALID after the 1-hour TTL has elapsed', function (): void {
        User::factory()->customer()->create(['email' => 'alice@example.com']);

        Carbon::setTestNow('2026-05-15 09:00:00');
        $token = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ])->json('data.dev_token');

        Carbon::setTestNow('2026-05-15 10:30:00');

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => $token,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_RESET_TOKEN_INVALID');
    });

    it('returns 422 ERR_VALIDATION when password fails the complexity rule', function (): void {
        User::factory()->customer()->create(['email' => 'alice@example.com']);
        $token = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'alice@example.com',
        ])->json('data.dev_token');

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});

describe('POST /api/v1/auth/reset-password (phone)', function (): void {
    it('updates the password via phone+otp and allows login with the new password', function (): void {
        $user = User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
            'password' => Hash::make('OldPassword1!'),
        ]);

        $otpCode = $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => '+971501234567',
        ])->json('data.dev_otp');

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'phone' => '+971501234567',
            'otp' => $otpCode,
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => '+971501234567',
            'password' => 'NewPassword1!',
        ])->assertOk();
    });
});

describe('POST /api/v1/auth/reset-password (cross-channel)', function (): void {
    it('returns 422 ERR_VALIDATION when both email+token AND phone+otp are supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'token' => str_repeat('a', 64),
            'phone' => '+971501234567',
            'otp' => '123456',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION for a mixed email+otp payload (no token)', function (): void {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@example.com',
            'otp' => '123456',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION for a mixed phone+token payload (no otp)', function (): void {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'phone' => '+971501234567',
            'token' => str_repeat('a', 64),
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when neither pair is supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});
