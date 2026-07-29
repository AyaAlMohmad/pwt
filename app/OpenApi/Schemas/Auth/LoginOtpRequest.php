<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginOtpRequest',
    required: ['phone'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971', nullable: true),
    ],
    type: 'object',
)]
final class LoginOtpRequest
{
    // Documentation-only.
}
