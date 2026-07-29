<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class EmailTakenException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.email_taken'),
            409,
            'ERR_EMAIL_TAKEN',
            ['email' => [__('auth.email_taken')]],
        );
    }
}
