<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * 200 OK paginated envelope for GET /api/v1/admin/categories.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminCategoryListResponse',
    required: ['success', 'message', 'data', 'meta'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/AdminCategoryDetail'),
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
final class AdminCategoryListResponse
{
    // Documentation-only.
}
