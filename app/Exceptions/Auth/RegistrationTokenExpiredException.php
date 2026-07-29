<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class RegistrationTokenExpiredException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.registration_token_expired'),
            401,
            'ERR_REGISTRATION_TOKEN_EXPIRED',
        );
    }
}
