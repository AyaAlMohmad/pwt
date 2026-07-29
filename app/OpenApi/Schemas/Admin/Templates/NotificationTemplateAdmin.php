<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Templates;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationTemplateAdmin',
    type: 'object',
    required: ['id', 'key', 'channel', 'subject', 'body', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 5),
        new OA\Property(property: 'key', type: 'string', example: 'order.confirmed'),
        new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'sms', 'email', 'whatsapp', 'push']),
        new OA\Property(
            property: 'subject',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', example: 'Your order is confirmed'),
                new OA\Property(property: 'ar', type: 'string', example: 'تم تأكيد طلبك'),
            ],
        ),
        new OA\Property(
            property: 'body',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string'),
                new OA\Property(property: 'ar', type: 'string'),
            ],
        ),
        new OA\Property(
            property: 'placeholders',
            type: 'array',
            items: new OA\Items(type: 'string'),
            example: ['order.id', 'order.public_id', 'order.status', 'user.name'],
        ),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
final class NotificationTemplateAdmin
{
    // Documentation-only.
}
