<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardResponse',
    type: 'object',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Dashboard snapshot retrieved.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/DashboardSnapshot'),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'cache_ttl_seconds', type: 'integer', example: 60),
                new OA\Property(property: 'cached', type: 'boolean', example: false),
                new OA\Property(property: 'period', type: 'string', enum: ['today', 'yesterday', 'last_7_days', 'this_week', 'this_month', 'last_month'], example: 'today'),
            ],
        ),
    ],
)]
final class DashboardResponse
{
    // Documentation-only.
}
