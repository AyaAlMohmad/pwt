<?php

declare(strict_types=1);

namespace App\Services\Auth;

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
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Password reset workflow.
 *
 * Always returns success regardless of whether the identifier resolves
 * to a real user (anti-enumeration). For unknown identifiers, we still
 * issue an active-looking placeholder return value to keep response
 * timing roughly constant; no DB row is created (we already block the
 * unknown-identifier path from disclosure).
 */
final class PasswordResetService
{
    private const EMAIL_TTL_MINUTES = 60;

    private const HOURLY_REQUEST_CAP = 3;

    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    /**
     * Request a password reset on the chosen channel.
     *
     * @throws OtpRateLimitException
     */
    public function requestReset(string $identifier, ResetChannel $channel, ?string $ip = null): PasswordReset
    {
        return DB::transaction(function () use ($identifier, $channel, $ip): PasswordReset {
            // Throttle 3/hour per identifier+channel (defence-in-depth
            // alongside the named route limiter).
            $recentCount = PasswordReset::query()
                ->where('identifier', $identifier)
                ->where('channel', $channel->value)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recentCount >= self::HOURLY_REQUEST_CAP) {
                throw OtpRateLimitException::create();
            }

            // Anti-enumeration: don't reveal whether the user exists.
            // If they don't, we still return a synthetic-looking reset
            // row in memory but do NOT persist it.
            $user = $this->findUserFor($identifier, $channel);

            if ($user === null) {
                $reset = new PasswordReset([
                    'channel' => $channel->value,
                    'identifier' => $identifier,
                    'token_hash' => null,
                    'expires_at' => now()->addMinutes(self::EMAIL_TTL_MINUTES),
                    'consumed_at' => null,
                    'ip' => $ip,
                ]);
                $reset->exists = false;

                return $reset;
            }

            // Supersede any prior unconsumed resets.
            PasswordReset::query()
                ->where('identifier', $identifier)
                ->where('channel', $channel->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($channel === ResetChannel::Email) {
                // Email channel: send OTP code instead of a link token.
                $otp = $this->otpService->createOtp($identifier, OtpPurpose::Reset, $ip);

                /** @var PasswordReset $reset */
                $reset = PasswordReset::query()->create([
                    'user_id' => $user->id,
                    'channel' => ResetChannel::Email->value,
                    'identifier' => $identifier,
                    'token_hash' => null,
                    'expires_at' => $otp->expires_at,
                    'consumed_at' => null,
                    'ip' => $ip,
                ]);

                $reset->devSecret = $otp->devCode;

                Log::info('PWD_RESET_EMAIL_START', [
                    'to' => $identifier,
                    'otp_code' => $otp->plainCode,
                    'ttl' => self::EMAIL_TTL_MINUTES,
                ]);

                try {
                    Mail::to($identifier)
                        ->queue(new PasswordResetMail($identifier, $otp->plainCode, self::EMAIL_TTL_MINUTES));
                    Log::info('PWD_RESET_EMAIL_QUEUED_OK', ['to' => $identifier]);
                } catch (\Throwable $e) {
                    Log::error('PWD_RESET_EMAIL_FAILED', [
                        'to' => $identifier,
                        'error' => $e->getMessage(),
                        'class' => get_class($e),
                    ]);
                    throw $e;
                }

                return $reset;
            }

            // Phone channel: parallel OTP row is the source of truth.
            Log::info('PWD_RESET_PHONE_START', [
                'to' => $identifier,
                'ttl' => self::EMAIL_TTL_MINUTES,
            ]);

            $otp = $this->otpService->requestOtp($identifier, OtpPurpose::Reset, $ip);

            Log::info('PWD_RESET_PHONE_OK', [
                'to' => $identifier,
                'otp_code' => $otp->plainCode,
                'otp_id' => $otp->public_id,
            ]);

            /** @var PasswordReset $reset */
            $reset = PasswordReset::query()->create([
                'user_id' => $user->id,
                'channel' => ResetChannel::Phone->value,
                'identifier' => $identifier,
                'token_hash' => null,
                'expires_at' => $otp->expires_at,
                'consumed_at' => null,
                'ip' => $ip,
            ]);

            $reset->devSecret = $otp->devCode;

            return $reset;
        });
    }

    /**
     * Verify an email-channel OTP and mint a short-lived reset token.
     *
     * @throws OtpInvalidException
     * @throws OtpExpiredException
     * @throws ResetTokenInvalidException
     */
    public function verifyResetOtp(string $email, string $otp): PasswordReset
    {
        return DB::transaction(function () use ($email, $otp): PasswordReset {
            // Validate the OTP (this also stamps consumed_at).
            $this->otpService->verifyOtp($email, $otp, OtpPurpose::Reset);

            $user = User::query()
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->first();

            if ($user === null) {
                throw ResetTokenInvalidException::create();
            }

            $plainToken = bin2hex(random_bytes(32)); // 64 hex chars

            /** @var PasswordReset $reset */
            $reset = PasswordReset::query()->create([
                'user_id' => $user->id,
                'channel' => ResetChannel::Email->value,
                'identifier' => $email,
                'token_hash' => Hash::make($plainToken),
                'expires_at' => now()->addMinutes(self::EMAIL_TTL_MINUTES),
                'consumed_at' => null,
                'ip' => null,
            ]);

            $reset->devSecret = $plainToken;

            return $reset;
        });
    }

    /**
     * Consume an email-channel reset (email + token).
     *
     * @throws ResetTokenInvalidException
     */
    public function consumeEmailReset(string $email, string $token, string $newPassword): void
    {
        DB::transaction(function () use ($email, $token, $newPassword): void {
            /** @var PasswordReset|null $reset */
            $reset = PasswordReset::query()
                ->where('identifier', $email)
                ->where('channel', ResetChannel::Email->value)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($reset === null || $reset->isExpired() || $reset->token_hash === null) {
                throw ResetTokenInvalidException::create();
            }

            if (! Hash::check($token, $reset->token_hash)) {
                throw ResetTokenInvalidException::create();
            }

            $user = User::query()
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->first();

            if ($user === null) {
                throw ResetTokenInvalidException::create();
            }

            $user->forceFill(['password' => Hash::make($newPassword)])->save();
            $user->tokens()->delete();
            $reset->markConsumed();
        });
    }

    /**
     * Consume a phone-channel reset (phone + otp).
     *
     * @throws OtpInvalidException
     * @throws OtpExpiredException
     */
    public function consumePhoneReset(string $phone, string $otp, string $newPassword): void
    {
        DB::transaction(function () use ($phone, $otp, $newPassword): void {
            // Validate the OTP (this also stamps consumed_at).
            /** @var Otp $verifiedOtp */
            $verifiedOtp = $this->otpService->verifyOtp($phone, $otp, OtpPurpose::Reset);

            // Portable (cc, local) lookup rather than CONCAT() (SQLite-friendly).
            [$cc, $local] = $this->splitE164($phone);
            $user = User::query()
                ->where('phone_country_code', $cc)
                ->where('phone', $local)
                ->whereNull('deleted_at')
                ->first();

            if ($user === null) {
                throw ResetTokenInvalidException::create();
            }

            $user->forceFill(['password' => Hash::make($newPassword)])->save();
            $user->tokens()->delete();

            // Mark the wrapper row consumed (mirrors the OTP).
            PasswordReset::query()
                ->where('identifier', $phone)
                ->where('channel', ResetChannel::Phone->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            // Silence unused-variable warning while keeping the
            // returned OTP for potential audit logging.
            unset($verifiedOtp);
        });
    }

    /**
     * Locate the user that owns this identifier (if any).
     */
    private function findUserFor(string $identifier, ResetChannel $channel): ?User
    {
        if ($channel === ResetChannel::Email) {
            return User::query()
                ->where('email', $identifier)
                ->whereNull('deleted_at')
                ->first();
        }

        // Portable (cc, local) lookup rather than CONCAT() (SQLite-friendly).
        [$cc, $local] = $this->splitE164($identifier);

        return User::query()
            ->where('phone_country_code', $cc)
            ->where('phone', $local)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Split an E.164 phone into the (country_code, local) pair stored on
     * `users`. Delegates to the shared normaliser so the dial-code probe
     * remains consistent across services.
     *
     * @return array{0: string, 1: string}
     */
    private function splitE164(string $identifier): array
    {
        $split = PhoneNumberNormaliser::split($identifier, null);
        $cc = $split['phone_country_code'] ?? '';
        $local = $split['phone'] ?? '';

        return [$cc, $local];
    }

}
