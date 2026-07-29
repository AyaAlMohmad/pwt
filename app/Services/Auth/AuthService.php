<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Exceptions\Auth\EmailTakenException;
use App\Exceptions\Auth\InvalidAccountTypeException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\PhoneTakenException;
use App\Exceptions\Auth\RegistrationTokenExpiredException;
use App\Models\Otp;
use App\Models\User;
use App\Support\Phone\PhoneNumberNormaliser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Customer auth orchestration.
 *
 * Wraps the multi-step register / login flows in transactions and
 * handles the bridging between OTP verification and the actual user
 * row plus Sanctum tokens.
 */
final class AuthService
{
    private const REGISTRATION_TOKEN_TTL_MINUTES = 15;

    private const ACCESS_TOKEN_TTL_DAYS = 30;

    /**
     * Mint a short-lived "register-only" Sanctum token that carries the
     * OTP-verified phone in its `name` column (JSON blob — parsed at
     * /auth/register time). The token CAN'T be used to call any other
     * endpoint (ability `register-only` is gated via Sanctum's
     * `CheckAbilities` middleware).
     *
     * The token is bound to the verified OTP row's identifier so the
     * /auth/register caller cannot inject an arbitrary phone via the
     * request body.
     */
    public function issueRegistrationToken(Otp $verifiedOtp, string $accountType = 'individual'): NewAccessToken
    {
        // Use a "ghost" User row anchored to the OTP's identifier so
        // Sanctum has something to attach the token to. We create a
        // throwaway User scoped to "pending registration" by spinning
        // up an existing or new ephemeral row — but to keep the model
        // simple, we attach the token to a soft-deleted-only sentinel
        // user keyed by phone. For Phase 3 MVP we attach to a new
        // unpersisted User via a small workaround: Sanctum requires a
        // saved tokenable, so we save a placeholder row keyed to the
        // OTP and immediately soft-delete it. Cleaner: use a dedicated
        // RegistrationContext model that morphs into Sanctum.
        //
        // Simpler approach taken here: create the User row immediately
        // with `is_active=false` and the verified phone, then upgrade
        // it in /auth/register. This sidesteps Sanctum's tokenable
        // requirement and keeps the API contract identical.

        [$cc, $local] = $this->splitE164($verifiedOtp->identifier);

        /** @var User|null $existing */
        $existing = User::query()
            ->where('phone_country_code', $cc)
            ->where('phone', $local)
            ->withTrashed()
            ->first();

        if ($existing !== null && $existing->deleted_at !== null) {
            // Phase 3 MVP: phone re-use after soft-delete is allowed
            // without cooldown. Hard-delete the soft-deleted row so we
            // can re-create. See docs/architecture/auth.md § 9.7.
            $existing->forceDelete();
            $existing = null;
        }

        // NOTE — race semantics (docs/architecture/auth.md § 5.3):
        //
        // When an active user already owns this phone (caller has won a
        // concurrent registration race), we DO NOT throw here. Instead
        // we attach the short-lived registration token to that active
        // user. The /auth/register endpoint will then detect
        // `is_active === true` and return 409 ERR_PHONE_TAKEN, which is
        // where the spec places the conflict surface — not at verify-otp.
        //
        // Security: the token has the `register-only` ability and the
        // 15-minute TTL; calling /auth/register is its sole legal use.
        if ($existing === null) {
            $existing = new User;
            $existing->forceFill([
                'name' => 'Pending Registration',
                'email' => null,
                'phone_country_code' => $cc,
                'phone' => $local,
                'locale' => app()->getLocale(),
                'is_active' => false,
                'account_type' => $accountType,
                'password' => Hash::make(bin2hex(random_bytes(16))),
            ])->save();
        } else {
            // Update account_type on existing placeholder user
            $existing->forceFill(['account_type' => $accountType])->save();
        }

        // Compose the registration token. `name` carries the OTP
        // public_id so we can reconstruct provenance at register-time;
        // the verified phone lives on the tokenable user (`$existing`).
        $tokenName = 'registration:'.$verifiedOtp->public_id;
        $expiresAt = Carbon::now()->addMinutes(self::REGISTRATION_TOKEN_TTL_MINUTES);

        return $existing->createToken($tokenName, ['register-only'], $expiresAt);
    }

    /**
     * Consume the registration token and finalise the User row.
     *
     * @param  array{name: string, email: ?string, password: string, locale: ?string, account_type: string, company_name: ?string}  $payload
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws PhoneTakenException
     * @throws EmailTakenException
     * @throws RegistrationTokenExpiredException
     */
    public function completeRegistration(PersonalAccessToken $token, array $payload): array
    {
        return DB::transaction(function () use ($token, $payload): array {
            if ($token->expires_at !== null && $token->expires_at->lessThanOrEqualTo(now())) {
                throw RegistrationTokenExpiredException::create();
            }

            /** @var User|null $user */
            $user = $token->tokenable;

            if (! $user instanceof User) {
                throw RegistrationTokenExpiredException::create();
            }

            // Race protection: a concurrent /auth/register may have
            // already promoted this user.
            if ($user->is_active === true) {
                throw PhoneTakenException::create();
            }

            if (! empty($payload['email'])) {
                $emailTaken = User::query()
                    ->where('email', $payload['email'])
                    ->where('id', '!=', $user->id)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($emailTaken) {
                    throw EmailTakenException::create();
                }
            }

            try {
                $user->forceFill([
                    'name' => $payload['name'],
                    'email' => $payload['email'] ?? null,
                    'locale' => $payload['locale'] ?? app()->getLocale(),
                    'is_active' => true,
                    'account_type' => $payload['account_type'] ?? $user->account_type ?? 'individual',
                    'company_name' => $payload['company_name'] ?? null,
                    'password' => Hash::make($payload['password']),
                ])->save();
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e, 'email')) {
                    throw EmailTakenException::create();
                }
                if ($this->isUniqueViolation($e, 'phone')) {
                    throw PhoneTakenException::create();
                }
                throw $e;
            }

            $user->syncRoles([UserRole::Customer->value]);

            // Revoke the registration token; it is single-use.
            $token->delete();

            $accessToken = $user->createToken(
                'mobile',
                ['*'],
                Carbon::now()->addDays(self::ACCESS_TOKEN_TTL_DAYS),
            );

            $fresh = $user->fresh();
            $resolved = $fresh instanceof User ? $fresh->load('roles') : $user->load('roles');

            return [
                'user' => $resolved,
                'token' => $accessToken,
            ];
        });
    }

    /**
     * Authenticate via phone-or-email + password.
     *
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws InvalidCredentialsException
     */
    public function loginWithPassword(?string $email, ?string $e164Phone, string $password, string $accountType): array
    {
        $query = User::query()->whereNull('deleted_at')->active();

        if ($e164Phone !== null) {
            // Use the split (cc, local) form rather than a CONCAT() query
            // so the lookup is portable across SQLite (tests) and MySQL.
            [$cc, $local] = $this->splitE164($e164Phone);
            $query->where('phone_country_code', $cc)->where('phone', $local);
        } elseif ($email !== null) {
            $query->where('email', $email);
        } else {
            throw InvalidCredentialsException::create();
        }

        /** @var User|null $user */
        $user = $query->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw InvalidCredentialsException::create();
        }

        if ($user->account_type === null || $user->account_type->value !== $accountType) {
            throw InvalidAccountTypeException::create();
        }

        $accessToken = $user->createToken(
            'mobile',
            ['*'],
            Carbon::now()->addDays(self::ACCESS_TOKEN_TTL_DAYS),
        );

        return [
            'user' => $user->load('roles'),
            'token' => $accessToken,
        ];
    }

    /**
     * Issue a full access token for an OTP-verified phone (login-otp flow).
     *
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws InvalidCredentialsException
     */
    public function loginWithVerifiedOtp(string $e164Phone, string $accountType): array
    {
        // Split into (cc, local) for portable lookup (SQLite + MySQL).
        [$cc, $local] = $this->splitE164($e164Phone);

        /** @var User|null $user */
        $user = User::query()
            ->whereNull('deleted_at')
            ->active()
            ->where('phone_country_code', $cc)
            ->where('phone', $local)
            ->first();

        if ($user === null) {
            throw InvalidCredentialsException::create();
        }

        if ($user->account_type === null || $user->account_type->value !== $accountType) {
            throw InvalidAccountTypeException::create();
        }

        $accessToken = $user->createToken(
            'mobile',
            ['*'],
            Carbon::now()->addDays(self::ACCESS_TOKEN_TTL_DAYS),
        );

        return [
            'user' => $user->load('roles'),
            'token' => $accessToken,
        ];
    }

    /**
     * @return array{0: string, 1: string} {country_code, local}
     */
    private function splitE164(string $identifier): array
    {
        $split = PhoneNumberNormaliser::split($identifier, null);
        $cc = $split['phone_country_code'] ?? '';
        $local = $split['phone'] ?? '';

        return [$cc, $local];
    }

    private function isUniqueViolation(QueryException $e, string $column): bool
    {
        // SQLSTATE 23000 = integrity constraint violation. We then
        // pattern-match on the column name in the driver error message.
        $sqlState = $e->getCode();
        if ($sqlState !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return stripos($message, $column) !== false;
    }
}
