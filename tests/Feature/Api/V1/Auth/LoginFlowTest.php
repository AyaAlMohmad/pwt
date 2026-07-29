<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    StubSmsDriver::flush();
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/login
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/login (password)', function (): void {
    it('returns 200 with a Bearer token and user profile when logging in by email', function (): void {
        $user = User::factory()->customer()->create([
            'email' => 'alice@example.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'email', 'phone', 'roles', 'is_active'],
                    'token' => ['access_token', 'token_type', 'expires_at', 'abilities'],
                ],
            ]);

        expect($response->json('data.user.id'))->toBe($user->id)
            ->and($response->json('data.user.email'))->toBe('alice@example.com')
            ->and($response->json('data.token.token_type'))->toBe('Bearer')
            ->and($response->json('data.token.abilities'))->toBe(['*']);
    });

    it('returns 200 when logging in by phone', function (): void {
        User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+971501234567',
            'password' => 'Password1!',
        ]);

        $response->assertOk();
        expect($response->json('data.user.phone'))->toBe('+971501234567');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS on a wrong password', function (): void {
        User::factory()->customer()->create([
            'email' => 'alice@example.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS for a soft-deleted user (no enumeration leak)', function (): void {
        $user = User::factory()->customer()->create([
            'email' => 'deleted@example.com',
            'password' => Hash::make('Password1!'),
        ]);
        $user->delete();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'deleted@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS for an inactive user (is_active=false)', function (): void {
        User::factory()->customer()->inactive()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });

    it('returns 422 ERR_VALIDATION when neither phone nor email is supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'Password1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when the password is missing', function (): void {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'alice@example.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 429 ERR_RATE_LIMITED after 6 attempts in one minute from the same IP+email', function (): void {
        User::factory()->customer()->create([
            'email' => 'rl@example.com',
            'password' => Hash::make('Password1!'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'rl@example.com',
                'password' => 'wrong',
            ]);
        }

        $sixth = $this->postJson('/api/v1/auth/login', [
            'email' => 'rl@example.com',
            'password' => 'wrong',
        ]);

        $sixth->assertStatus(429)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/login-otp + /verify
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/login-otp', function (): void {
    it('returns 200 with otp_id, expires_at, dev_otp and purpose=login', function (): void {
        $response = $this->postJson('/api/v1/auth/login-otp', [
            'phone' => '+971501234567',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['otp_id', 'purpose', 'expires_at', 'dev_otp'],
            ])
            ->assertJsonPath('data.purpose', 'login');

        expect($response->json('data.dev_otp'))->toMatch('/^\d{6}$/');
    });

    it('dispatches one SMS with purpose=login', function (): void {
        $this->postJson('/api/v1/auth/login-otp', [
            'phone' => '+971501234567',
        ])->assertOk();

        $dispatched = StubSmsDriver::dispatched();
        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['context']['purpose'])->toBe('login');
    });

    it('returns 422 ERR_VALIDATION when phone is missing', function (): void {
        $response = $this->postJson('/api/v1/auth/login-otp', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});

describe('POST /api/v1/auth/login-otp/verify', function (): void {
    it('returns 200 with a 30-day token when the OTP matches an active customer', function (): void {
        User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $otp = app(OtpService::class)->requestOtp('+971501234567', OtpPurpose::Login);

        $response = $this->postJson('/api/v1/auth/login-otp/verify', [
            'phone' => '+971501234567',
            'otp' => $otp->devCode,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'phone'],
                    'token' => ['access_token', 'token_type', 'expires_at', 'abilities'],
                ],
            ]);

        expect($response->json('data.user.phone'))->toBe('+971501234567')
            ->and($response->json('data.token.abilities'))->toBe(['*']);
    });

    it('returns 422 ERR_INVALID_OTP when the code is wrong', function (): void {
        User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);
        app(OtpService::class)->requestOtp('+971501234567', OtpPurpose::Login);

        $response = $this->postJson('/api/v1/auth/login-otp/verify', [
            'phone' => '+971501234567',
            'otp' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_INVALID_OTP');
    });

    it('returns 401 ERR_INVALID_CREDENTIALS when the OTP is valid but the user is inactive', function (): void {
        User::factory()->customer()->inactive()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);
        $otp = app(OtpService::class)->requestOtp('+971501234567', OtpPurpose::Login);

        $response = $this->postJson('/api/v1/auth/login-otp/verify', [
            'phone' => '+971501234567',
            'otp' => $otp->devCode,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_INVALID_CREDENTIALS');
    });
});
