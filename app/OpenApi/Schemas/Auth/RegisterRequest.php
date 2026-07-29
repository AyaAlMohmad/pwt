<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    required: ['name', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120, example: 'Ahmed Al Rashidi'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 160, nullable: true, example: 'ahmed@example.com', description: 'Optional in MVP (see docs/architecture/auth.md § 9.1).'),
        new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'Password123!', description: 'Min 8 chars; must include mixed case, letters and numbers.'),
        new OA\Property(property: 'password_confirmation', type: 'string', example: 'Password123!'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], nullable: true, example: 'en'),
    ],
    type: 'object',
)]
final class RegisterRequest
{
    // Documentation-only.
}
