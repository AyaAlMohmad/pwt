<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportCustomer',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 412),
        new OA\Property(property: 'name', type: 'string', example: 'Sarah Al-Mansouri'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true,
            example: 'sarah@example.ae'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+971501234567',
            description: 'E.164 — country code prefix + national number.'),
    ],
    type: 'object',
)]
final class AdminSupportCustomer
{
    // Documentation-only.
}
