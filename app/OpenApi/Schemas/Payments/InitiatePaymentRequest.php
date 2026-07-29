<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InitiatePaymentRequest',
    required: ['order_id', 'payment_method'],
    properties: [
        new OA\Property(property: 'order_id', type: 'integer', example: 1057),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod'], example: 'card'),
    ],
    type: 'object',
)]
final class InitiatePaymentRequest
{
    // Documentation-only.
}
