<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class OtpExpiredException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.otp_expired'),
            422,
            'ERR_OTP_EXPIRED',
            ['otp' => [__('auth.otp_expired')]],
        );
    }
}
