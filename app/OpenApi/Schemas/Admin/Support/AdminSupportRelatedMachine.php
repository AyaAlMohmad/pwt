<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportRelatedMachine',
    required: ['id', 'public_id'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'serial_number', type: 'string'),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(
            property: 'product',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'code', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminSupportRelatedMachine
{
    // Documentation-only.
}
