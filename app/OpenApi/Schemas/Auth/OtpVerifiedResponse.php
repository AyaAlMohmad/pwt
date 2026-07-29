<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OtpVerifiedResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Verification successful.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AuthToken'),
    ],
    type: 'object',
)]
final class OtpVerifiedResponse
{
    // Documentation-only.
}
