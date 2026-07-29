<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Catalog;

use OpenApi\Attributes as OA;

/**
 * 200 / 201 OK envelope for the single-category endpoints.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminCategoryDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminCategoryDetail'),
    ],
    type: 'object',
)]
final class AdminCategoryDetailResponse
{
    // Documentation-only.
}
