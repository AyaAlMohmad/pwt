<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class OtpExhaustedException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.otp_exhausted'),
            422,
            'ERR_OTP_EXHAUSTED',
            ['otp' => [__('auth.otp_exhausted')]],
        );
    }
}
