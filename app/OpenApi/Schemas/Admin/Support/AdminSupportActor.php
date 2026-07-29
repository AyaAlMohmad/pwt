<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportActor',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', nullable: true, example: 7,
            description: 'Null only for `system`-actor rows.'),
        new OA\Property(property: 'name', type: 'string', example: 'Hassan Khalil'),
        new OA\Property(property: 'role', type: 'string', nullable: true, example: 'support',
            description: "Primary role: 'super_admin' | 'support' | 'operations' | 'sales' | 'customer' | 'system'."),
    ],
    type: 'object',
)]
final class AdminSupportActor
{
    // Documentation-only.
}
