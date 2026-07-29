<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterOtpRequest',
    required: ['phone'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567', description: 'E.164 phone, or a bare local number when `phone_country_code` is supplied.'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971', nullable: true, description: 'Dial code (with `+`). Required only when `phone` does NOT start with `+`.'),
    ],
    type: 'object',
)]
final class RegisterOtpRequest
{
    // Documentation-only.
}
