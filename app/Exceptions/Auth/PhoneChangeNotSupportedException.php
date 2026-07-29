<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

/**
 * Phase 3 MVP: changing a phone number via PATCH /me is not supported.
 * The full re-verify flow is queued for Phase 13.
 */
final class PhoneChangeNotSupportedException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.phone_change_unsupported'),
            409,
            'ERR_PHONE_CHANGE_NOT_SUPPORTED',
        );
    }
}
