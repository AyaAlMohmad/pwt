<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

final class OtpRateLimitException extends AuthException
{
    public static function create(): self
    {
        return new self(
            __('messages.rate_limited'),
            429,
            'ERR_RATE_LIMITED',
        );
    }
}
