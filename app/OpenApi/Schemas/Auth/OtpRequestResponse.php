<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OtpRequestResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'A verification code has been sent.'),
        new OA\Property(
            property: 'data',
            required: ['otp_id', 'purpose', 'expires_at'],
            properties: [
                new OA\Property(property: 'otp_id', type: 'string', format: 'uuid', example: '8b1e6c1c-2d3e-4f5a-9b0c-1234567890ab'),
                new OA\Property(property: 'purpose', type: 'string', enum: ['register', 'login', 'reset'], example: 'register'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-05-15T09:35:00+00:00'),
                new OA\Property(property: 'dev_otp', type: 'string', nullable: true, example: '482917', description: 'Only present when APP_ENV is NOT production.'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class OtpRequestResponse
{
    // Documentation-only.
}
