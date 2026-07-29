<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuthSuccessResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Welcome back.'),
        new OA\Property(
            property: 'data',
            required: ['user', 'token'],
            properties: [
                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                new OA\Property(property: 'token', ref: '#/components/schemas/AuthToken'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class AuthSuccessResponse
{
    // Documentation-only.
}
