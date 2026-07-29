<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResetPasswordRequest',
    description: 'Two valid shapes: `(email + token)` OR `(phone + otp)`. Mixed pairs are rejected with 422 ERR_RESET_TOKEN_INVALID.',
    required: ['password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'ahmed@example.com'),
        new OA\Property(property: 'token', type: 'string', minLength: 64, maxLength: 64, pattern: '^[a-f0-9]{64}$', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', nullable: true, example: '+971'),
        new OA\Property(property: 'otp', type: 'string', minLength: 6, maxLength: 6, pattern: '^\\d{6}$', nullable: true),
        new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'NewPass123!'),
        new OA\Property(property: 'password_confirmation', type: 'string', example: 'NewPass123!'),
    ],
    type: 'object',
)]
final class ResetPasswordRequest
{
    // Documentation-only.
}
