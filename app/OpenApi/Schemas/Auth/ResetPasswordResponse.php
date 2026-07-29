<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResetPasswordResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Your password has been updated. Please log in again.'),
        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
    ],
    type: 'object',
)]
final class ResetPasswordResponse
{
    // Documentation-only.
}
