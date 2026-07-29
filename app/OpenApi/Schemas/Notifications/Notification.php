<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Notifications;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Notification',
    required: ['id', 'public_id', 'type', 'title', 'body', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 4815),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid', example: 'f3e1c2d4-9c80-4d2f-8a4f-c2b9b1c2e7a1'),
        new OA\Property(property: 'type', type: 'string', example: 'order.confirmed', description: 'Event template key — e.g. order.confirmed, payment.captured, maintenance.assigned.'),
        new OA\Property(property: 'title', type: 'string', example: 'Your order is confirmed'),
        new OA\Property(property: 'body', type: 'string', example: 'Order #1057 has been confirmed and will be scheduled for installation soon.'),
        new OA\Property(
            property: 'data',
            type: 'object',
            nullable: true,
            example: ['order_id' => 1057, 'order_public_id' => '0a3a1f4f-9c80-4d2f-8a4f-c2b9b1c2e7a1'],
            additionalProperties: true,
        ),
        new OA\Property(property: 'action_url', type: 'string', nullable: true, example: 'https://app.pwt.example/orders/1057'),
        new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T11:05:00+04:00'),
    ],
    type: 'object',
)]
final class Notification
{
    // Documentation-only.
}
