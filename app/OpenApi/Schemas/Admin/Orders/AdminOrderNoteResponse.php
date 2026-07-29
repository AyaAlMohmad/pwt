<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminOrderNoteResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Note added.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminOrderStatusHistoryEntry'),
    ],
    type: 'object',
)]
final class AdminOrderNoteResponse
{
    // Documentation-only.
}
