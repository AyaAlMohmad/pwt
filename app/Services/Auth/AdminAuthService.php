<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Exceptions\Auth\AdminInactiveException;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Facades\CauserResolver;

/**
 * Admin auth orchestration.
 *
 * Handles email + password login, current-token logout, and the
 * capability-map derivation used by the dashboard to drive client-side
 * page guards and nav rendering.
 *
 * The login path is anti-enumeration-hardened: a customer hitting the
 * admin login surface gets the SAME 401 envelope as a wrong-password
 * attempt — see docs/architecture/admin-auth.md § 5.3.
 */
final class AdminAuthService
{
    /**
     * Anti-enumeration placeholder bcrypt hash. Used when the email
     * lookup returns no user so Hash::check() still runs and the
     * timing profile of known vs unknown emails is indistinguishable.
     *
     * MUST be a syntactically valid bcrypt hash ($2y$12$, 60 chars)
     * because Hash::check() throws on invalid bcrypt input — see
     * docs/architecture/admin-auth.md § 5.3.
     */
    private const PLACEHOLDER_PASSWORD_HASH = '$2y$12$ABaUsLm9oeNFOhI8GhJj5euvD9YwflYTw02D7LtbCmoyUbcrnRO46';

    /**
     * Role priority order used to resolve a single role name for an
     * admin who somehow holds multiple admin roles (data
     * inconsistency). Highest privilege first.
     *
     * @var list<string>
     */
    private const ROLE_PRIORITY = [
        'super_admin',
        'operations',
        'sales',
        'support',
    ];

    /**
     * Static, fixed-shape capability map. Adding a new permission key
     * to RolesAndPermissionsSeeder REQUIRES extending this map — if a
     * key arrives that isn't enumerated below it is silently dropped,
     * which the test suite guards against.
     *
     * @var array<string, array<int, string>>
     */
    private const CAPABILITY_MAP = [
        'users' => ['view', 'create', 'update', 'deactivate'],
        'products' => ['view', 'create', 'update', 'deactivate', 'manage-images'],
        'categories' => ['view', 'create', 'update', 'delete'],
        'orders' => ['view', 'update-status', 'cancel', 'schedule', 'cancel-any', 'confirm', 'refund'],
        'maintenance' => ['view', 'create', 'assign', 'update', 'close'],
        'machines' => ['view', 'create', 'update'],
        'support' => ['view', 'reply', 'update', 'create', 'cancel'],
        'promotions' => ['view', 'create', 'update', 'delete'],
        'banners' => ['view', 'create', 'update', 'delete'],
        'testimonials' => ['view', 'create', 'update', 'delete'],
        'reports' => ['view', 'export'],
        'audit_logs' => ['view'],
        'notifications' => ['view', 'templates'],
    ];

    /**
     * Authenticate an admin by email + password.
     *
     * Cases (a)..(e) per docs/architecture/admin-auth.md § 5.1 all
     * collapse to the same 401 envelope so external observers cannot
     * enumerate accounts. The ACTIVE inactive-account distinction is
     * raised AFTER credentials are verified, since by that point the
     * caller already proved knowledge of the password.
     *
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws InvalidCredentialsException
     * @throws AdminInactiveException
     */
    public function login(string $email, string $password, ?string $ip = null): array
    {
        $normalised = mb_strtolower(trim($email));

        /** @var User|null $user */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalised])
            ->first();

        // Anti-enumeration: hash a fixed dummy regardless of whether
        // the user was found so the timing profile of a known vs
        // unknown email is identical.
        $passwordHash = $user !== null
            ? $user->password
            : self::PLACEHOLDER_PASSWORD_HASH;
        $passwordMatches = Hash::check($password, $passwordHash);

        if (
            $user === null
            || ! $passwordMatches
            || $user->hasRole(UserRole::Customer->value)
            || ! $user->hasAnyRole(UserRole::adminValues())
        ) {
            $this->logFailedAttempt($normalised, $ip);
            throw InvalidCredentialsException::create();
        }

        if ($user->is_active !== true) {
            throw AdminInactiveException::create();
        }

        $role = $this->resolveAdminRole($user);
        if ($role === null) {
            // Defensive: hasAnyRole() said yes, resolveAdminRole said
            // no. Treat as creds failure (same anti-enumeration
            // envelope) rather than leak the inconsistency.
            $this->logFailedAttempt($normalised, $ip);
            throw InvalidCredentialsException::create();
        }

        $expiresAt = Carbon::now()->addHours(
            (int) config('admin.token_ttl_hours', 8),
        );

        $token = $user->createToken('admin', ['admin'], $expiresAt);

        $user->forceFill(['last_login_at' => Carbon::now()])->save();
        $user->refresh();

        $this->logActivity(
            event: 'admin.login',
            description: 'Admin logged in',
            admin: $user,
            properties: [
                'ip' => $ip,
                'role' => $role,
                'token_id' => $token->accessToken->getKey(),
            ],
        );

        $user->load('roles.permissions');

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke the current admin Sanctum token.
     *
     * @throws AuthenticationException when no current token is present
     */
    public function logout(User $admin): void
    {
        /** @var PersonalAccessToken|null $token */
        $token = $admin->currentAccessToken();

        if ($token === null) {
            throw new AuthenticationException;
        }

        $tokenId = $token->getKey();

        $token->delete();

        $this->logActivity(
            event: 'admin.logout',
            description: 'Admin logged out',
            admin: $admin,
            properties: [
                'token_id' => $tokenId,
            ],
        );
    }

    /**
     * Build the fixed-shape capability map for the dashboard SPA.
     *
     * Every domain and action enumerated in CAPABILITY_MAP is present
     * in the result regardless of role; values are booleans. The shape
     * is stable so the dashboard can write static checks like
     * `capabilities.orders.cancel === true`.
     *
     * @return array<string, array<string, bool>>
     */
    public function buildCapabilities(User $admin): array
    {
        $granted = $admin
            ->getAllPermissions()
            ->pluck('name')
            ->all();

        $grantedSet = array_fill_keys(array_map('strval', $granted), true);

        $capabilities = [];
        foreach (self::CAPABILITY_MAP as $domain => $actions) {
            $capabilities[$domain] = [];
            foreach ($actions as $action) {
                $permissionKey = $domain.'.'.$action;
                $actionKey = str_replace('-', '_', $action);
                $capabilities[$domain][$actionKey] = isset($grantedSet[$permissionKey]);
            }
        }

        return $capabilities;
    }

    /**
     * Resolve the single admin role for the given user.
     *
     * Picks the highest-privilege role from ROLE_PRIORITY if the user
     * somehow holds more than one admin role. Returns null when no
     * admin role is present.
     */
    public function resolveAdminRole(User $admin): ?string
    {
        $names = $admin->getRoleNames()->all();

        foreach (self::ROLE_PRIORITY as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Flat sorted list of permission keys granted to this admin.
     *
     * @return list<string>
     */
    public function permissionKeys(User $admin): array
    {
        $keys = $admin
            ->getAllPermissions()
            ->pluck('name')
            ->map(static fn ($name): string => (string) $name)
            ->unique()
            ->values()
            ->all();

        sort($keys);

        return $keys;
    }

    /**
     * Write a Spatie ActivityLog entry for an admin auth event.
     *
     * Uses the activity() helper directly rather than the LogsActivity
     * model trait because the auth events aren't attribute changes on
     * the User row — they're side-effects triggered by service calls.
     *
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        string $event,
        string $description,
        User $admin,
        array $properties,
    ): void {
        // Ensure the activity row has a causer even though the
        // request lifecycle may not have set one yet (login happens
        // before any auth:sanctum middleware fires).
        CauserResolver::setCauser($admin);

        activity('auth')
            ->event($event)
            ->causedBy($admin)
            ->performedOn($admin)
            ->withProperties($properties)
            ->log($description);
    }

    private function logFailedAttempt(string $email, ?string $ip): void
    {
        Log::info('admin_login_failed', [
            'email' => $this->maskEmail($email),
            'ip' => $ip,
        ]);
    }

    /**
     * Mask a PII email for safe logging — keeps the first character
     * of the local-part and the domain, scrubs the rest.
     */
    private function maskEmail(string $email): string
    {
        $atPos = strpos($email, '@');
        if ($atPos === false || $atPos === 0) {
            return '***';
        }

        $localFirst = substr($email, 0, 1);
        $domain = substr($email, $atPos);

        return $localFirst.'***'.$domain;
    }
}
