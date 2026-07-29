<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentStatusResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Payment status retrieved'),
        new OA\Property(property: 'data', ref: '#/components/schemas/Payment'),
    ],
    type: 'object',
)]
final class PaymentStatusResponse
{
    // Documentation-only.
}
