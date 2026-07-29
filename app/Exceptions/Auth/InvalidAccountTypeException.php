<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class InvalidAccountTypeException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.invalid_account_type'),
            401,
            'ERR_INVALID_ACCOUNT_TYPE',
        );
    }
}
