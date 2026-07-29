<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class ResetTokenInvalidException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.reset_token_invalid'),
            422,
            'ERR_RESET_TOKEN_INVALID',
            ['token' => [__('auth.reset_token_invalid')]],
        );
    }
}
