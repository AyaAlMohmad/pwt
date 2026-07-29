<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Notifications;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'NotificationResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Notification marked as read.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/Notification'),
    ],
    type: 'object',
)]
final class NotificationResponse
{
    // Documentation-only.
}
