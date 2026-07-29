<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\User;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateProfileRequest',
    description: 'Partial profile update. Changing `email` requires `current_password`. Changing `phone` is rejected with 409 in MVP.',
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120, nullable: true, example: 'Ahmed Al Rashidi'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'ahmed@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567', description: 'Currently rejected with 409 ERR_PHONE_CHANGE_NOT_SUPPORTED.'),
        new OA\Property(property: 'phone_country_code', type: 'string', nullable: true, example: '+971'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], nullable: true),
        new OA\Property(property: 'current_password', type: 'string', nullable: true, description: 'Required when changing email or phone.'),
    ],
    type: 'object',
)]
final class UpdateProfileRequest
{
    // Documentation-only.
}
