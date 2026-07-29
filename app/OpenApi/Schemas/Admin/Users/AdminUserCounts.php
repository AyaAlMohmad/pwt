<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Users;

use OpenApi\Attributes as OA;

/**
 * Nested object inside {@see AdminUserDetail::$counts}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminUserCounts',
    required: ['orders_count', 'support_tickets_count', 'machines_count'],
    properties: [
        new OA\Property(property: 'orders_count', type: 'integer', minimum: 0, example: 12),
        new OA\Property(property: 'support_tickets_count', type: 'integer', minimum: 0, example: 3),
        new OA\Property(property: 'machines_count', type: 'integer', minimum: 0, example: 1),
    ],
    type: 'object',
)]
final class AdminUserCounts
{
    // Documentation-only.
}
