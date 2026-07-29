<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Notifications;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationCollectionResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Notifications retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Notification'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
                new OA\Property(property: 'unread_count', type: 'integer', example: 12),
            ],
        ),
    ],
    type: 'object',
)]
final class NotificationCollectionResponse
{
    // Documentation-only.
}
