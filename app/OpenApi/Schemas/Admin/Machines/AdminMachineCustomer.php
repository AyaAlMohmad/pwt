<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Machines;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminMachineCustomer',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 412),
        new OA\Property(property: 'name', type: 'string', example: 'Sarah Al-Mansouri'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'sarah@example.ae'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567'),
        // The customer's default address book entry — distinct from the
        // machine's own `address` (installation site). Null when the
        // customer has no address.
        new OA\Property(
            property: 'address',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 17),
                new OA\Property(property: 'label', type: 'string', nullable: true, example: 'Main kitchen'),
                new OA\Property(property: 'line1', type: 'string', example: 'Mall of the Emirates, Level 1'),
                new OA\Property(property: 'line2', type: 'string', nullable: true, example: null),
                new OA\Property(property: 'area', type: 'string', nullable: true, example: 'Al Barsha'),
                new OA\Property(property: 'city', type: 'string', nullable: true, example: 'Dubai'),
                new OA\Property(property: 'region', type: 'string', nullable: true, example: 'Dubai'),
                new OA\Property(
                    property: 'country',
                    type: 'object',
                    nullable: true,
                    properties: [new OA\Property(property: 'code', type: 'string', example: 'ae')],
                ),
                new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 25.1181),
                new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 55.2003),
                new OA\Property(property: 'is_default', type: 'boolean', example: true),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminMachineCustomer
{
    // Documentation-only.
}
