<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class OtpInvalidException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('auth.otp_invalid'),
            422,
            'ERR_INVALID_OTP',
            ['otp' => [__('auth.otp_invalid')]],
        );
    }
}
