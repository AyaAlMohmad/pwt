<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuthToken',
    required: ['access_token', 'token_type', 'expires_at', 'abilities'],
    properties: [
        new OA\Property(property: 'access_token', type: 'string', example: '1|aBcDeFgHiJkL...'),
        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-06-14T09:30:00+00:00'),
        new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string'), example: ['*']),
    ],
    type: 'object',
)]
final class AuthToken
{
    // Documentation-only.
}
