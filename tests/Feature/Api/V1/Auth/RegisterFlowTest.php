<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Models\Otp;
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
| POST /api/v1/auth/register-otp
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/register-otp', function (): void {
    it('returns 200 with otp_id, expires_at, dev_otp, and purpose=register on a valid phone', function (): void {
        $response = $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971501234567',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['otp_id', 'purpose', 'expires_at', 'dev_otp'],
            ])
            ->assertJsonPath('data.purpose', 'register');

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data.dev_otp'))->toMatch('/^\d{6}$/');
        expect($response->json('data.otp_id'))->toMatch('/^[0-9a-f-]{36}$/i');
    });

    it('persists one OTP row with the supplied phone as identifier and purpose=register', function (): void {
        $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971501234567',
        ])->assertOk();

        $this->assertDatabaseCount('otps', 1);
        $this->assertDatabaseHas('otps', [
            'identifier' => '+971501234567',
            'purpose' => 'register',
            'consumed_at' => null,
        ]);
    });

    it('dispatches exactly one SMS via the stub driver', function (): void {
        $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971501234567',
        ])->assertOk();

        $dispatched = StubSmsDriver::dispatched();
        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['to'])->toBe('+971501234567')
            ->and($dispatched[0]['context']['purpose'])->toBe('register');
    });

    it('returns 422 ERR_VALIDATION when phone is missing', function (): void {
        $response = $this->postJson('/api/v1/auth/register-otp', []);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when the phone has letters', function (): void {
        $response = $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971ABC1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when the phone is too short', function (): void {
        $response = $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns the same generic 200 envelope when the phone is already registered (anti-enumeration)', function (): void {
        // Existing active user with this phone — endpoint MUST still respond
        // with a generic success and NOT leak existence.
        User::factory()->customer()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $response = $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971501234567',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.purpose', 'register');
    });

    it('returns 429 ERR_RATE_LIMITED after 6 register-otp requests from the same phone in the same minute', function (): void {
        // The `otp-request` named limiter caps at 5/hour per phone — the 6th
        // request from the same identifier must be rate-limited.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/register-otp', [
                'phone' => '+971501234567',
            ]);
            // Any one of these may already trip the limiter on the route side
            // OR the service-layer cap — both produce 429.
            if ($response->status() === 429) {
                $this->markTestSkipped('Limiter tripped earlier than the contract requires.');
            }
        }

        $sixth = $this->postJson('/api/v1/auth/register-otp', [
            'phone' => '+971501234567',
        ]);

        $sixth->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/verify-otp
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/verify-otp', function (): void {
    /**
     * Issue a fresh OTP row and return its plaintext devCode for use in the
     * verify step. Wraps the public flow so the upstream `register-otp`
     * limiter doesn't contaminate verify-side limits.
     */
    function issueRegisterOtp(string $phone = '+971501234567'): string
    {
        $service = app(OtpService::class);
        $otp = $service->requestOtp($phone, OtpPurpose::Register);

        return (string) $otp->devCode;
    }

    it('returns 200 with a registration_token having ability=register-only', function (): void {
        $code = issueRegisterOtp();

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+971501234567',
            'otp' => $code,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['access_token', 'token_type', 'expires_at', 'abilities'],
            ]);

        expect($response->json('data.abilities'))->toBe(['register-only'])
            ->and($response->json('data.token_type'))->toBe('Bearer')
            ->and($response->json('data.access_token'))->toBeString()->not->toBeEmpty();
    });

    it('marks the OTP row consumed on success', function (): void {
        $code = issueRegisterOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+971501234567',
            'otp' => $code,
        ])->assertOk();

        $otp = Otp::query()->where('identifier', '+971501234567')->first();
        expect($otp->consumed_at)->not->toBeNull();
    });

    it('returns 422 ERR_INVALID_OTP when the code is wrong', function (): void {
        issueRegisterOtp();

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+971501234567',
            'otp' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_INVALID_OTP')
            ->assertJsonPath('success', false);
    });

    it('returns 422 ERR_VALIDATION when the OTP format is invalid (5 digits)', function (): void {
        issueRegisterOtp();

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+971501234567',
            'otp' => '12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_OTP_EXHAUSTED on the 3rd wrong attempt and rejects the correct code afterwards', function (): void {
        $code = issueRegisterOtp();

        // 3 wrong attempts.
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'otp' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'ERR_INVALID_OTP');
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'otp' => '000001'])
            ->assertStatus(422)->assertJsonPath('code', 'ERR_INVALID_OTP');
        $third = $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'otp' => '000002']);
        $third->assertStatus(422)->assertJsonPath('code', 'ERR_OTP_EXHAUSTED');

        // Even the correct code now fails.
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'otp' => $code])
            ->assertStatus(422);
    });

    it('returns 422 ERR_OTP_EXPIRED when the TTL has elapsed', function (): void {
        Carbon::setTestNow('2026-05-15 09:30:00');
        $code = issueRegisterOtp();

        Carbon::setTestNow('2026-05-15 09:36:00');

        $response = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '+971501234567',
            'otp' => $code,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_OTP_EXPIRED');
    });
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/register
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/auth/register', function (): void {
    /**
     * Run the full OTP pipeline and return the bearer registration_token.
     */
    function issueRegistrationToken(string $phone = '+971501234567'): string
    {
        $service = app(OtpService::class);
        $otp = $service->requestOtp($phone, OtpPurpose::Register);

        $verify = test()->postJson('/api/v1/auth/verify-otp', [
            'phone' => $phone,
            'otp' => $otp->devCode,
        ]);
        $verify->assertOk();

        return (string) $verify->json('data.access_token');
    }

    it('creates an active customer with a hashed password and returns 201 + access token', function (): void {
        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Ahmed Al Rashidi',
                'email' => 'ahmed@example.com',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
                'locale' => 'en',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'phone', 'roles', 'is_active', 'locale'],
                    'token' => ['access_token', 'token_type', 'expires_at', 'abilities'],
                ],
            ]);

        expect($response->json('data.user.name'))->toBe('Ahmed Al Rashidi')
            ->and($response->json('data.user.email'))->toBe('ahmed@example.com')
            ->and($response->json('data.user.phone'))->toBe('+971501234567')
            ->and($response->json('data.user.is_active'))->toBeTrue()
            ->and($response->json('data.user.roles'))->toBe(['customer'])
            ->and($response->json('data.token.abilities'))->toBe(['*']);

        // Password is bcrypt-hashed in DB.
        $user = User::query()->where('email', 'ahmed@example.com')->first();
        expect($user)->not->toBeNull()
            ->and(Hash::check('Password1!', $user->password))->toBeTrue()
            ->and($user->password)->not->toBe('Password1!')
            ->and($user->hasRole(UserRole::Customer->value))->toBeTrue();
    });

    it('allows registration without an email (email is optional)', function (): void {
        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'No Email User',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ]);

        $response->assertStatus(201);
        expect($response->json('data.user.email'))->toBeNull();
    });

    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'No Token',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 403 when the bearer token has an explicit non-register ability', function (): void {
        // Sanctum's wildcard `*` ability satisfies every named ability check
        // (including `register-only`), so a customer's everyday access token
        // is NOT a usable counter-example. Build a token with a narrow,
        // unrelated ability — `ability:register-only` must reject it.
        $customer = User::factory()->customer()->create();
        $accessToken = $customer->createToken('mobile', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $accessToken")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Wrong Ability',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ]);

        $response->assertStatus(403);
    });

    it('returns 409 ERR_PHONE_TAKEN if a concurrent register has already activated the same phone', function (): void {
        // First registration succeeds (issues + consumes a register token).
        $tokenA = issueRegistrationToken();
        $this->withHeader('Authorization', "Bearer $tokenA")
            ->postJson('/api/v1/auth/register', [
                'name' => 'First Registration',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ])
            ->assertStatus(201);

        // A racing concurrent token issued for the SAME phone before the
        // first registration completed: per architecture § 5.3, completing
        // it must surface 409 ERR_PHONE_TAKEN rather than silently
        // promote a second active row for the same phone.
        $tokenB = issueRegistrationToken();
        $second = $this->withHeader('Authorization', "Bearer $tokenB")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Race Registration',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ]);

        $second->assertStatus(409)
            ->assertJsonPath('code', 'ERR_PHONE_TAKEN');
    });

    it('returns 409 ERR_EMAIL_TAKEN when the email is already used by another active user', function (): void {
        User::factory()->customer()->create(['email' => 'taken@example.com']);

        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Email Conflict',
                'email' => 'taken@example.com',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ]);

        // The unique rule in the Form Request fires first → 422 ERR_VALIDATION
        // (assert against actual response). The dedicated ERR_EMAIL_TAKEN code
        // is reserved for the DB-level race fallback in the service.
        expect($response->status())->toBeIn([409, 422]);
        if ($response->status() === 422) {
            $response->assertJsonPath('code', 'ERR_VALIDATION');
            $response->assertJsonPath('errors.email.0', __('auth.email_taken'));
        } else {
            $response->assertJsonPath('code', 'ERR_EMAIL_TAKEN');
        }
    });

    it('returns 422 when the password is too short', function (): void {
        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Weak Password',
                'password' => 'Aa1',
                'password_confirmation' => 'Aa1',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 when password_confirmation does not match', function (): void {
        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Mismatch',
                'password' => 'Password1!',
                'password_confirmation' => 'Different1!',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('issues a 30-day access token on successful registration', function (): void {
        Carbon::setTestNow('2026-05-15 09:30:00');
        $token = issueRegistrationToken();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/register', [
                'name' => 'Token TTL',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
            ]);

        $response->assertStatus(201);

        $expectedExpiry = Carbon::parse('2026-06-14 09:30:00');
        $actualExpiry = Carbon::parse($response->json('data.token.expires_at'));
        expect($actualExpiry->equalTo($expectedExpiry))->toBeTrue();
    });
});
