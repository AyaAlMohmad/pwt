<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Users;

use OpenApi\Attributes as OA;

/**
 * 200 OK envelope for GET /api/v1/admin/users.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminUserListResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AdminUserDetail'),
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
final class AdminUserListResponse
{
    // Documentation-only.
}
