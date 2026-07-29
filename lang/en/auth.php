<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // OTP flow
    'otp_message' => 'PWT verification code: :code. Valid for :minutes minutes.',
    'otp_sent' => 'A verification code has been sent.',
    'otp_invalid' => 'The verification code is invalid.',
    'otp_expired' => 'The verification code has expired. Please request a new one.',
    'otp_exhausted' => 'Too many incorrect attempts. Please request a new code.',
    'otp_verified' => 'Verification successful.',

    // Phone / Email
    'account_type_invalid' => 'The account type must be either individual or company.',
    'company_name_required' => 'Company name is required for business accounts.',
    'phone_invalid' => 'The phone number is not in a valid format.',
    'phone_taken' => 'This phone number is already registered.',
    'email_taken' => 'This email address is already registered.',
    'phone_change_unsupported' => 'Phone change requires re-verification (coming soon).',

    // Registration / login
    'registration_token_expired' => 'Registration session expired. Please verify your phone again.',
    'register_success' => 'Account created.',
    'login_success' => 'Welcome back.',
    'logout_success' => 'You have been logged out.',
    'invalid_credentials' => 'The provided credentials are incorrect.',
    'invalid_account_type' => 'The account type does not match our records.',

    // Password reset
    'reset_sent' => 'If the account exists, password reset instructions have been sent.',
    'reset_token_invalid' => 'The reset token is invalid or has expired.',
    'reset_success' => 'Your password has been updated. Please log in again.',
    'reset_email_subject' => 'Reset your PWT password',
    'reset_email_body' => 'A password reset has been requested for your account. To proceed, open this link within :minutes minutes: :url. If you did not request this, ignore this email.',
    'reset_email_otp_body' => 'Your password reset code is: :code. It is valid for :minutes minutes. If you did not request this, ignore this email.',

    // Profile
    'profile_updated' => 'Profile updated.',
    'avatar_updated' => 'Avatar updated.',
    'account_deleted' => 'Your account has been deleted.',
    'password_required' => 'Your current password is required to perform this action.',

    // Payment Cards
    'payment_cards' => [
        'retrieved' => 'Payment cards retrieved.',
        'created' => 'Payment card added.',
        'shown' => 'Payment card retrieved.',
        'updated' => 'Payment card updated.',
        'deleted' => 'Payment card deleted.',
        'default_set' => 'Default payment card updated.',
    ],
];
