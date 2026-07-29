<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Support\Http\ApiResponse;
use OpenApi\Attributes as OA;

/**
 * Standard pagination meta block returned under `meta.pagination` on
 * every paginated list endpoint. Shape mirrors Laravel's default
 * `LengthAwarePaginator` output (see {@see ApiResponse}).
 *
 * Documentation-only — no PHP body.
 */
#[OA\Schema(
    schema: 'Pagination',
    required: ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 2),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 16),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 15),
    ],
    type: 'object',
)]
final class Pagination
{
    // Documentation-only.
}
