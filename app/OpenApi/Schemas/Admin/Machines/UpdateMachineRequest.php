<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateMachineRequest',
    description: 'All fields optional. Every mutable machine column is editable; only `id`, `public_id` and the timestamps are rejected. Reassigning `user_id` requires an `address_id` owned by the new customer in the same request.',
    properties: [
        new OA\Property(property: 'user_id', type: 'integer', description: 'Reassign ownership. Target must hold the customer role. Requires address_id in the same request.'),
        new OA\Property(property: 'product_id', type: 'integer', description: 'Reassign the linked product. Must be an active product.'),
        new OA\Property(property: 'serial_number', type: 'string', maxLength: 80),
        new OA\Property(property: 'term', type: 'string', enum: ['buy', 'rent', 'foc']),
        new OA\Property(property: 'installed_at', type: 'string', format: 'date'),
        new OA\Property(property: 'filter_change_interval_months', type: 'integer', maximum: 12, minimum: 1, nullable: true, example: 6),
        new OA\Property(property: 'next_maintenance_due_at', type: 'string', format: 'date', nullable: true, description: 'Explicit override; when supplied, bypasses scheduler auto-recompute.'),
        new OA\Property(property: 'address_id', type: 'integer'),
        new OA\Property(property: 'location_label', type: 'string', maxLength: 160, nullable: true),
        new OA\Property(property: 'country_code', type: 'string', minLength: 2, maxLength: 2),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'voltage', type: 'string', enum: ['110V', '220V', 'Dual'], nullable: true, description: 'Case-insensitive on input; normalised to the canonical casing.', example: '220V'),
        new OA\Property(property: 'individual_buy_enabled', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'source', type: 'string', enum: ['imported', 'created']),
        new OA\Property(property: 'order_id', type: 'integer', nullable: true),
        new OA\Property(property: 'order_installation_id', type: 'integer', nullable: true),
    ],
    type: 'object',
)]
final class UpdateMachineRequest
{
    // Documentation-only.
}
