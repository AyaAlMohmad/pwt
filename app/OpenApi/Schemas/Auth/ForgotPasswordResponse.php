<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ForgotPasswordResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'If the account exists, password reset instructions have been sent.'),
        new OA\Property(
            property: 'data',
            required: ['channel', 'expires_at'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', enum: ['email', 'phone'], example: 'email'),
                new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', example: '2026-05-15T10:30:00+00:00'),
                new OA\Property(property: 'dev_token', type: 'string', nullable: true, description: 'Only present when APP_ENV is NOT production AND channel=email.'),
                new OA\Property(property: 'dev_otp', type: 'string', nullable: true, description: 'Only present when APP_ENV is NOT production AND channel=phone.'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
final class ForgotPasswordResponse
{
    // Documentation-only.
}
