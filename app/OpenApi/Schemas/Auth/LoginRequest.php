<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoginRequest',
    required: ['password'],
    properties: [
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567', nullable: true, description: 'Required if `email` is not provided.'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971', nullable: true),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'ahmed@example.com', description: 'Required if `phone` is not provided.'),
        new OA\Property(property: 'password', type: 'string', example: 'Password123!'),
        new OA\Property(property: 'remember_device', type: 'boolean', example: false, nullable: true, description: 'Reserved for future use; ignored in MVP.'),
    ],
    type: 'object',
)]
final class LoginRequest
{
    // Documentation-only.
}
