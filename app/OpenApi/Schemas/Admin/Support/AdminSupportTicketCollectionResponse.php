<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Support;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminSupportTicketCollectionResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Support tickets retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AdminSupportTicket'),
        ),
        new OA\Property(
            property: 'meta',
            type: 'object',
            properties: [
                new OA\Property(property: 'pagination', ref: '#/components/schemas/Pagination'),
            ],
        ),
    ],
    type: 'object',
)]
final class AdminSupportTicketCollectionResponse
{
    // Documentation-only.
}
