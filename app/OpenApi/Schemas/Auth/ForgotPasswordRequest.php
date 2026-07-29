<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ForgotPasswordRequest',
    description: 'Exactly one of `email` or `phone` must be present.',
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'ahmed@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', nullable: true, example: '+971'),
    ],
    type: 'object',
)]
final class ForgotPasswordRequest
{
    // Documentation-only.
}
