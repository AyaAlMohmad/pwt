<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentInitiateResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Payment initiated'),
        new OA\Property(property: 'data', allOf: [
            new OA\Schema(ref: '#/components/schemas/Payment'),
            new OA\Schema(properties: [
                new OA\Property(property: 'session_id', type: 'string', example: 'sdk_token_abc123', nullable: true),
                new OA\Property(property: 'payment_reference', type: 'string', example: 'f1ee2c0b-1f7d-4b8a-8c61-8f1a9d2e5e02', nullable: true),
                new OA\Property(property: 'payment_token', type: 'string', nullable: true),
                new OA\Property(property: 'client_secret', type: 'string', example: 'pi_abc123_secret_xyz', nullable: true),
                new OA\Property(property: 'order', type: 'object', nullable: true),
            ]),
        ]),
    ],
    type: 'object',
)]
final class PaymentInitiateResponse
{
    // Documentation-only.
}
