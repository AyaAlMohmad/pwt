<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateMachineRequest',
    description: 'All fields optional. `user_id` and `product_id` are immutable.',
    properties: [
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 80),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date'),
        new OA\Property(property: 'filter_change_interval_months', type: 'integer', enum: [3, 6, 12], nullable: true),
        new OA\Property(property: 'next_maintenance_due_at', type: 'string', format: 'date', nullable: true, description: 'Explicit override; when supplied, bypasses scheduler auto-recompute.'),
        new OA\Property(property: 'address_id', type: 'integer'),
        new OA\Property(property: 'location_label', type: 'string', maxLength: 160, nullable: true),
        new OA\Property(property: 'country_code', type: 'string', minLength: 2, maxLength: 2),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
    type: 'object',
)]
final class UpdateMachineRequest
{
    // Documentation-only.
}
