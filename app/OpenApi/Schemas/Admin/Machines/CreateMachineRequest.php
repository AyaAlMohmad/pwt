<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateMachineRequest',
    required: ['user_id', 'product_id', 'serial_number', 'term', 'installed_at', 'country_code'],
    properties: [
        new OA\Property(property: 'user_id', type: 'integer', example: 412, description: 'Owning customer id; must hold the customer role.'),
        new OA\Property(property: 'product_id', type: 'integer', example: 12, description: 'Active product id.'),
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 80, example: 'PW50M-AE-001234'),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc'], example: 'rent'),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date', example: '2025-03-12'),
        new OA\Property(property: 'filter_change_interval_months', type: 'integer', maximum: 12, minimum: 1, nullable: true, example: 6),
        new OA\Property(property: 'next_maintenance_due_at', type: 'string', format: 'date', nullable: true, description: 'Optional explicit override; requires filter_change_interval_months to be set.'),
        new OA\Property(property: 'country_code', type: 'string', minLength: 2, maxLength: 2, example: 'AE'),
        new OA\Property(property: 'voltage', type: 'string', enum: ['110V', '220V', 'Dual'], nullable: true, description: 'Case-insensitive on input; normalised to the canonical casing.', example: '220V'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class CreateMachineRequest
{
    // Documentation-only.
}
