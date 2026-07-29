<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreAdminMaintenanceRequest',
    required: ['customer_id', 'machine_id', 'issue_type', 'description'],
    properties: [
        new OA\Property(property: 'customer_id', type: 'integer', example: 412, description: 'Must hold the customer role.'),
        new OA\Property(property: 'machine_id', type: 'integer', example: 423, description: 'Must belong to customer_id and be active.'),
        new OA\Property(property: 'issue_type', type: 'string', enum: ['filter_change', 'leak', 'noise', 'low_pressure', 'water_quality', 'error_code', 'installation_fix', 'other']),
        new OA\Property(property: 'description', type: 'string', minLength: 5, maxLength: 2000),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent'], description: 'No clamp for admins.'),
        new OA\Property(property: 'preferred_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'internal_notes', type: 'string', maxLength: 2000, nullable: true, description: 'Admin-only; never returned on customer surface.'),
    ],
    type: 'object',
)]
final class StoreAdminMaintenanceRequest
{
    // Documentation-only.
}
