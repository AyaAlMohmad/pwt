<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Enums\ResetChannel;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitException;
use App\Exceptions\Auth\ResetTokenInvalidException;
use App\Mail\PasswordResetMail;
use App\Models\Otp;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    StubSmsDriver::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('PasswordResetService::requestReset (email channel)', function (): void {
    it('creates a row and queues the password reset mail when the email matches an active user', function (): void {
        $user = User::factory()->create(['email' => 'alice@example.com']);

        $reset = app(PasswordResetService::class)
            ->requestReset('alice@example.com', ResetChannel::Email, '127.0.0.1');

        expect($reset)->toBeInstanceOf(PasswordReset::class)
            ->and($reset->exists)->toBeTrue()
            ->and($reset->user_id)->toBe($user->id)
            ->and($reset->channel)->toBe(ResetChannel::Email)
            ->and($reset->identifier)->toBe('alice@example.com')
            ->and($reset->token_hash)->toStartWith('$2y$')
            ->and($reset->consumed_at)->toBeNull();

        // Non-prod env surfaces the plaintext token via the transient attribute.
        expect($reset->devSecret)->toBeString()
            ->and($reset->devSecret)->toHaveLength(64);
        expect(Hash::check($reset->devSecret, $reset->token_hash))->toBeTrue();

        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $mail) use ($reset): bool {
            return $mail->email === 'alice@example.com'
                && str_contains($mail->resetUrl, $reset->devSecret);
        });
    });

    it('writes a synthetic non-persisted row and skips mail when the email is unknown', function (): void {
        $reset = app(PasswordResetService::class)
            ->requestReset('ghost@example.com', ResetChannel::Email, '127.0.0.1');

        expect($reset)->toBeInstanceOf(PasswordReset::class)
            ->and($reset->exists)->toBeFalse();

        Mail::assertNothingQueued();
        expect(PasswordReset::query()->count())->toBe(0);
    });

    it('supersedes prior unconsumed resets for the same identifier+channel', function (): void {
        $user = User::factory()->create(['email' => 'alice@example.com']);
        $service = app(PasswordResetService::class);

        $first = $service->requestReset('alice@example.com', ResetChannel::Email);
        $second = $service->requestReset('alice@example.com', ResetChannel::Email);

        expect($first->fresh()->consumed_at)->not->toBeNull()
            ->and($second->fresh()->consumed_at)->toBeNull();
    });

    it('throws OtpRateLimitException after the 3rd request in one hour', function (): void {
        $user = User::factory()->create(['email' => 'alice@example.com']);
        $service = app(PasswordResetService::class);

        for ($i = 0; $i < 3; $i++) {
            $service->requestReset('alice@example.com', ResetChannel::Email);
        }

        $service->requestReset('alice@example.com', ResetChannel::Email);
    })->throws(OtpRateLimitException::class);
});

describe('PasswordResetService::requestReset (phone channel)', function (): void {
    beforeEach(function (): void {
        // Phone-channel queries use a raw CONCAT(phone_country_code, phone)
        // expression which is MySQL-specific; SQLite reports "no such function:
        // CONCAT". Skipped under SQLite — covered against the pwt_testing
        // MySQL database in integration runs.
        if (DB::connection()->getDriverName() === 'sqlite') {
            test()->markTestSkipped('CONCAT() is not portable to SQLite — covered on MySQL only.');
        }
    });

    it('creates a row plus a parallel OTP and sends SMS when the phone matches an active user', function (): void {
        User::factory()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $reset = app(PasswordResetService::class)
            ->requestReset('+971501234567', ResetChannel::Phone, '127.0.0.1');

        expect($reset->exists)->toBeTrue()
            ->and($reset->channel)->toBe(ResetChannel::Phone)
            ->and($reset->identifier)->toBe('+971501234567')
            ->and($reset->token_hash)->toBeNull();

        // Companion OTP exists.
        $otp = Otp::query()
            ->where('identifier', '+971501234567')
            ->where('purpose', OtpPurpose::Reset->value)
            ->first();
        expect($otp)->not->toBeNull();

        // SMS dispatched with the OTP context.
        expect(StubSmsDriver::lastDispatched())->not->toBeNull()
            ->and(StubSmsDriver::lastDispatched()['context']['purpose'])->toBe('reset');

        // devSecret on the reset row matches the OTP devCode in non-prod.
        expect($reset->devSecret)->toBe($otp->devCode);
    });

    it('returns a synthetic row with no DB rows when the phone is unknown', function (): void {
        $reset = app(PasswordResetService::class)
            ->requestReset('+971500000000', ResetChannel::Phone);

        expect($reset->exists)->toBeFalse();
        expect(PasswordReset::query()->count())->toBe(0);
        expect(Otp::query()->count())->toBe(0);
        expect(StubSmsDriver::dispatched())->toBeEmpty();
    });
});

describe('PasswordResetService::consumeEmailReset', function (): void {
    it('updates the password, revokes all tokens, and marks the reset row consumed', function (): void {
        $user = User::factory()->create([
            'email' => 'alice@example.com',
            'password' => Hash::make('OldPassword1!'),
        ]);
        $user->createToken('mobile-1');
        $user->createToken('mobile-2');

        $service = app(PasswordResetService::class);
        $reset = $service->requestReset('alice@example.com', ResetChannel::Email);
        $token = $reset->devSecret;

        $service->consumeEmailReset('alice@example.com', $token, 'NewPassword1!');

        $user->refresh();
        expect(Hash::check('NewPassword1!', $user->password))->toBeTrue()
            ->and(Hash::check('OldPassword1!', $user->password))->toBeFalse();

        expect($user->tokens()->count())->toBe(0);

        expect($reset->fresh()->consumed_at)->not->toBeNull();
    });

    it('throws ResetTokenInvalidException on a bad token', function (): void {
        User::factory()->create(['email' => 'alice@example.com']);
        $service = app(PasswordResetService::class);
        $service->requestReset('alice@example.com', ResetChannel::Email);

        $service->consumeEmailReset('alice@example.com', str_repeat('z', 64), 'NewPassword1!');
    })->throws(ResetTokenInvalidException::class);

    it('throws ResetTokenInvalidException once the row is expired', function (): void {
        User::factory()->create(['email' => 'alice@example.com']);

        Carbon::setTestNow('2026-05-15 09:00:00');
        $service = app(PasswordResetService::class);
        $reset = $service->requestReset('alice@example.com', ResetChannel::Email);
        $token = (string) $reset->devSecret;

        Carbon::setTestNow('2026-05-15 10:30:00');

        $service->consumeEmailReset('alice@example.com', $token, 'NewPassword1!');
    })->throws(ResetTokenInvalidException::class);

    it('throws ResetTokenInvalidException when no reset row exists for the email', function (): void {
        app(PasswordResetService::class)
            ->consumeEmailReset('nobody@example.com', str_repeat('a', 64), 'NewPassword1!');
    })->throws(ResetTokenInvalidException::class);
});

describe('PasswordResetService::consumePhoneReset', function (): void {
    beforeEach(function (): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            test()->markTestSkipped('CONCAT() is not portable to SQLite — covered on MySQL only.');
        }
    });

    it('updates the password, revokes tokens, marks the reset consumed', function (): void {
        $user = User::factory()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
            'password' => Hash::make('OldPassword1!'),
        ]);
        $user->createToken('mobile-1');

        $service = app(PasswordResetService::class);
        $reset = $service->requestReset('+971501234567', ResetChannel::Phone);
        $otpCode = (string) $reset->devSecret;

        $service->consumePhoneReset('+971501234567', $otpCode, 'NewPassword1!');

        $user->refresh();
        expect(Hash::check('NewPassword1!', $user->password))->toBeTrue()
            ->and($user->tokens()->count())->toBe(0)
            ->and($reset->fresh()->consumed_at)->not->toBeNull();
    });

    it('throws when the OTP is wrong', function (): void {
        User::factory()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $service = app(PasswordResetService::class);
        $service->requestReset('+971501234567', ResetChannel::Phone);

        $service->consumePhoneReset('+971501234567', '000000', 'NewPassword1!');
    })->throws(OtpInvalidException::class);

    it('throws when the OTP has expired', function (): void {
        User::factory()->create([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        Carbon::setTestNow('2026-05-15 09:00:00');
        $service = app(PasswordResetService::class);
        $reset = $service->requestReset('+971501234567', ResetChannel::Phone);
        $code = (string) $reset->devSecret;

        Carbon::setTestNow('2026-05-15 09:10:00');

        $service->consumePhoneReset('+971501234567', $code, 'NewPassword1!');
    })->throws(OtpExpiredException::class);
});
