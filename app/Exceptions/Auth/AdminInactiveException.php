<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

/**
 * Raised when a disabled admin attempts to obtain (or use) an
 * admin Sanctum token.
 *
 * Distinct from `InvalidCredentialsException` so the dashboard can
 * present a "your account is disabled" UX rather than the generic
 * "invalid credentials" string. Anti-enumeration is preserved at the
 * service layer: this exception is ONLY surfaced AFTER credentials
 * have been verified — so disclosing "inactive" tells the caller
 * nothing they did not already know (they had the password).
 */
final class AdminInactiveException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('admin.inactive'),
            403,
            'ERR_ADMIN_INACTIVE',
        );
    }
}
