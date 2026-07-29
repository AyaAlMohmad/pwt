<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VerifyOtpRequest',
    required: ['phone', 'otp'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971', nullable: true),
        new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, pattern: '^\\d{6}$', example: '482917'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VerifyLoginOtpRequest',
    required: ['phone', 'otp'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971', nullable: true),
        new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, pattern: '^\\d{6}$', example: '482917'),
    ],
    type: 'object',
)]
final class VerifyOtpRequest
{
    // Documentation-only.
}
