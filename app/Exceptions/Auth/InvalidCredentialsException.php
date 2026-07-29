<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class InvalidCredentialsException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.invalid_credentials'),
            401,
            'ERR_INVALID_CREDENTIALS',
        );
    }
}
