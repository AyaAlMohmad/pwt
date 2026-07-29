<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Notifications;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ReadAllNotificationsResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'All notifications marked as read.'),
        new OA\Property(property: 'data', nullable: true, example: null),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'updated_count', type: 'integer', example: 12),
            ],
        ),
    ],
    type: 'object',
)]
final class ReadAllNotificationsResponse
{
    // Documentation-only.
}
