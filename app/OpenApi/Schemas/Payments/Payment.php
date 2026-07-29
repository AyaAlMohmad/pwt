<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Payment',
    required: ['payment_id', 'public_id', 'order_id', 'status', 'payment_method', 'gateway', 'amount', 'currency'],
    properties: [
        new OA\Property(property: 'payment_id', type: 'integer', example: 401),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid', example: 'f1ee2c0b-1f7d-4b8a-8c61-8f1a9d2e5e02'),
        new OA\Property(property: 'order_id', type: 'integer', example: 1057),
        new OA\Property(property: 'status', type: 'string', enum: ['initiated', 'authorized', 'captured', 'failed', 'cancelled', 'refunded']),
        new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'gateway', type: 'string', example: 'stub'),
        new OA\Property(property: 'amount', type: 'string', example: '2362.50'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'payment_url', type: 'string', nullable: true, example: 'http://127.0.0.1:8000/stub-payment-checkout?session=8a3f...'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
final class Payment
{
    // Documentation-only.
}
