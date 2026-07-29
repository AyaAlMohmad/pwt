<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateOrderStatusRequest',
    required: ['status'],
    properties: [
        new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['pending', 'confirmed', 'out_for_delivery', 'installed', 'completed', 'cancelled'],
            description: '`scheduled` is rejected (409 ERR_USE_SCHEDULE_ENDPOINT) — use POST /schedule-installation instead.',
            example: 'confirmed',
        ),
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 1000, nullable: true, example: 'COD payment collected on doorstep'),
    ],
    type: 'object',
)]
final class UpdateOrderStatusRequest
{
    // Documentation-only.
}
