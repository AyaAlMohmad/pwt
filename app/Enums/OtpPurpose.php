<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Purpose of an OTP record.
 *
 * Backed string enum; persisted via `App\Models\Otp::$casts`.
 *
 * - `Register`           — first-time customer registration (phase 3).
 * - `Login`              — passwordless login OTP.
 * - `Reset`              — password reset, phone channel.
 * - `UpdatePhone`        — reserved for the phone re-verification flow
 *                          (deferred to Phase 13; not currently routed).
 * - `EmailVerification`  — verify ownership of the user's email address.
 */
enum OtpPurpose: string
{
    case Register = 'register';
    case Login = 'login';
    case Reset = 'reset';
    case UpdatePhone = 'update_phone';
    case EmailVerification = 'email_verification';
}
