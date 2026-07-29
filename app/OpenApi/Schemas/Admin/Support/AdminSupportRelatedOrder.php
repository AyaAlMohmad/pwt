<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportRelatedOrder',
    required: ['id', 'public_id', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1057),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'total_amount', type: 'string', example: '1250.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
final class AdminSupportRelatedOrder
{
    // Documentation-only.
}
