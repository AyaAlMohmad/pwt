<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderPayment',
    required: ['id', 'public_id', 'status', 'method', 'amount', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4501),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['initiated', 'authorized', 'captured', 'failed', 'cancelled', 'refunded']),
        new OA\Property(property: 'method', type: 'string', enum: ['card', 'apple_pay', 'google_pay', 'cod']),
        new OA\Property(property: 'amount', type: 'string', example: '2362.50'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'gateway', type: 'string', example: 'payfort'),
        new OA\Property(property: 'gateway_reference', type: 'string', nullable: true, example: 'PFT-2026-99281'),
        new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
        new OA\Property(
            property: 'events',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'type', type: 'string', example: 'webhook.captured'),
                    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
                ],
            ),
        ),
    ],
    type: 'object',
)]
final class AdminOrderPayment
{
    // Documentation-only.
}
