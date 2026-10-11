<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class EmailNotVerifiedException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.email_not_verified'),
            422,
            'ERR_EMAIL_NOT_VERIFIED',
            ['email' => [__('auth.email_not_verified')]],
        );
    }
}
