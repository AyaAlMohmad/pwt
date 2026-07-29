<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Channel used to dispatch a password reset.
 *
 * - `Email` — opaque 64-hex token mailed to the identifier.
 * - `Phone` — 6-digit OTP, source-of-truth lives in the parallel `otps` row.
 */
enum ResetChannel: string
{
    case Email = 'email';
    case Phone = 'phone';
}
