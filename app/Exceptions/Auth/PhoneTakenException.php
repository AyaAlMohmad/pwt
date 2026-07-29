<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class PhoneTakenException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.phone_taken'),
            409,
            'ERR_PHONE_TAKEN',
            ['phone' => [__('auth.phone_taken')]],
        );
    }
}
