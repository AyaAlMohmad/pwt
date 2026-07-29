<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Exceptions\Auth\OtpExhaustedException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitException;
use App\Models\Otp;
use App\Services\Auth\OtpService;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    StubSmsDriver::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('OtpService::requestOtp', function (): void {
    it('creates a hashed OTP row with 5-minute TTL and attempts=0', function (): void {
        Carbon::setTestNow('2026-05-15 09:30:00');

        $service = app(OtpService::class);
        $otp = $service->requestOtp('+971501234567', OtpPurpose::Register, '127.0.0.1');

        expect($otp)->toBeInstanceOf(Otp::class)
            ->and($otp->identifier)->toBe('+971501234567')
            ->and($otp->purpose)->toBe(OtpPurpose::Register)
            ->and($otp->attempts)->toBe(0)
            ->and($otp->max_attempts)->toBe(3)
            ->and($otp->consumed_at)->toBeNull()
            ->and($otp->ip)->toBe('127.0.0.1')
            ->and($otp->expires_at->equalTo(Carbon::parse('2026-05-15 09:35:00')))->toBeTrue();

        // Code hash is bcrypt of the actual code — must not be plaintext.
        expect($otp->code_hash)->toStartWith('$2y$')
            ->and($otp->code_hash)->not->toBe($otp->devCode);

        // Plaintext code is surfaced via the transient dev attribute (non-prod env).
        expect($otp->devCode)->toBeString()
            ->and($otp->devCode)->toMatch('/^\d{6}$/');

        expect(Hash::check($otp->devCode, $otp->code_hash))->toBeTrue();
    });

    it('dispatches a single SMS via the configured driver', function (): void {
        $service = app(OtpService::class);
        $service->requestOtp('+971501234567', OtpPurpose::Register);

        $dispatched = StubSmsDriver::dispatched();
        expect($dispatched)->toHaveCount(1)
            ->and($dispatched[0]['to'])->toBe('+971501234567')
            ->and($dispatched[0]['context']['purpose'])->toBe('register');
    });

    it('invalidates any prior unconsumed OTP for the same (identifier, purpose) pair', function (): void {
        $service = app(OtpService::class);

        $first = $service->requestOtp('+971501234567', OtpPurpose::Register);
        $second = $service->requestOtp('+971501234567', OtpPurpose::Register);

        $first->refresh();

        expect($first->consumed_at)->not->toBeNull()
            ->and($second->consumed_at)->toBeNull();
    });

    it('does NOT invalidate OTPs for a different purpose on the same identifier', function (): void {
        $service = app(OtpService::class);

        $register = $service->requestOtp('+971501234567', OtpPurpose::Register);
        $login = $service->requestOtp('+971501234567', OtpPurpose::Login);

        $register->refresh();

        expect($register->consumed_at)->toBeNull()
            ->and($login->consumed_at)->toBeNull();
    });

    it('throws OtpRateLimitException after the 5th request in the same hour', function (): void {
        $service = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->requestOtp('+971501234567', OtpPurpose::Register);
        }

        $service->requestOtp('+971501234567', OtpPurpose::Register);
    })->throws(OtpRateLimitException::class);

    it('attaches HTTP 429 + ERR_RATE_LIMITED metadata to the rate-limit exception', function (): void {
        $service = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->requestOtp('+971501234567', OtpPurpose::Register);
        }

        try {
            $service->requestOtp('+971501234567', OtpPurpose::Register);
            $this->fail('Expected OtpRateLimitException was not thrown.');
        } catch (OtpRateLimitException $e) {
            expect($e->status)->toBe(429)
                ->and($e->errorCode)->toBe('ERR_RATE_LIMITED');
        }
    });

    it('resets the hourly cap once requests fall outside the rolling window', function (): void {
        $service = app(OtpService::class);

        Carbon::setTestNow('2026-05-15 09:00:00');
        for ($i = 0; $i < 5; $i++) {
            $service->requestOtp('+971501234567', OtpPurpose::Register);
        }

        Carbon::setTestNow('2026-05-15 10:30:00');
        $sixth = $service->requestOtp('+971501234567', OtpPurpose::Register);

        expect($sixth)->toBeInstanceOf(Otp::class);
    });
});

describe('OtpService::verifyOtp', function (): void {
    it('marks the row consumed and returns it on a successful match', function (): void {
        $service = app(OtpService::class);
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Register);

        $verified = $service->verifyOtp('+971501234567', (string) $issued->devCode, OtpPurpose::Register);

        expect($verified->id)->toBe($issued->id)
            ->and($verified->consumed_at)->not->toBeNull()
            ->and($verified->isConsumed())->toBeTrue();
    });

    it('throws OtpInvalidException when no active OTP exists for the (identifier, purpose) pair', function (): void {
        app(OtpService::class)->verifyOtp('+971509999999', '123456', OtpPurpose::Register);
    })->throws(OtpInvalidException::class);

    it('increments attempts and throws OtpInvalidException on a wrong code', function (): void {
        $service = app(OtpService::class);
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Register);

        try {
            $service->verifyOtp('+971501234567', '000000', OtpPurpose::Register);
            $this->fail('Expected OtpInvalidException not thrown.');
        } catch (OtpInvalidException) {
            // Expected
        }

        $issued->refresh();
        expect($issued->attempts)->toBe(1)
            ->and($issued->consumed_at)->toBeNull();
    });

    it('throws OtpExhaustedException on the 3rd wrong attempt and invalidates the row', function (): void {
        $service = app(OtpService::class);
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Register);

        // First two wrong attempts produce OtpInvalidException.
        for ($i = 0; $i < 2; $i++) {
            try {
                $service->verifyOtp('+971501234567', '000000', OtpPurpose::Register);
                $this->fail('Expected OtpInvalidException not thrown.');
            } catch (OtpInvalidException) {
                // Expected
            }
        }

        // 3rd wrong attempt → OtpExhaustedException + row consumed.
        try {
            $service->verifyOtp('+971501234567', '000000', OtpPurpose::Register);
            $this->fail('Expected OtpExhaustedException not thrown.');
        } catch (OtpExhaustedException $e) {
            expect($e->status)->toBe(422)
                ->and($e->errorCode)->toBe('ERR_OTP_EXHAUSTED');
        }

        $issued->refresh();
        expect($issued->attempts)->toBe(3)
            ->and($issued->consumed_at)->not->toBeNull();
    });

    it('rejects the correct code once the row has been exhausted', function (): void {
        $service = app(OtpService::class);
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Register);
        $correct = (string) $issued->devCode;

        for ($i = 0; $i < 3; $i++) {
            try {
                $service->verifyOtp('+971501234567', '000000', OtpPurpose::Register);
            } catch (OtpInvalidException|OtpExhaustedException) {
                // Expected
            }
        }

        // Even the correct code now fails — the row is gone.
        $service->verifyOtp('+971501234567', $correct, OtpPurpose::Register);
    })->throws(OtpInvalidException::class);

    it('throws OtpExpiredException once the TTL has passed', function (): void {
        $service = app(OtpService::class);

        Carbon::setTestNow('2026-05-15 09:30:00');
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Register);
        $correct = (string) $issued->devCode;

        Carbon::setTestNow('2026-05-15 09:36:00');

        try {
            $service->verifyOtp('+971501234567', $correct, OtpPurpose::Register);
            $this->fail('Expected OtpExpiredException not thrown.');
        } catch (OtpExpiredException $e) {
            expect($e->status)->toBe(422)
                ->and($e->errorCode)->toBe('ERR_OTP_EXPIRED');
        }
    });

    it('throws OtpInvalidException when matching an OTP from a different purpose', function (): void {
        $service = app(OtpService::class);
        $issued = $service->requestOtp('+971501234567', OtpPurpose::Login);

        // The code is valid only for Login.
        $service->verifyOtp('+971501234567', (string) $issued->devCode, OtpPurpose::Register);
    })->throws(OtpInvalidException::class);
});

describe('OtpService::invalidateActive', function (): void {
    it('marks every active OTP for the (identifier, purpose) pair consumed', function (): void {
        $service = app(OtpService::class);

        $reset = $service->requestOtp('+971501234567', OtpPurpose::Reset);
        $other = $service->requestOtp('+971501234567', OtpPurpose::Login);

        $service->invalidateActive('+971501234567', OtpPurpose::Reset);

        expect($reset->fresh()->consumed_at)->not->toBeNull()
            ->and($other->fresh()->consumed_at)->toBeNull();
    });

    it('is a no-op when no active OTP exists', function (): void {
        $service = app(OtpService::class);
        $service->invalidateActive('+971509999999', OtpPurpose::Register);

        expect(Otp::query()->count())->toBe(0);
    });
});
