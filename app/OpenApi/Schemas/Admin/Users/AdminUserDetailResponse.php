<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Users;

use OpenApi\Attributes as OA;

/**
 * 200 OK envelope for GET / PATCH / DELETE /api/v1/admin/users/{user}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminUserDetailResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminUserDetail'),
    ],
    type: 'object',
)]
final class AdminUserDetailResponse
{
    // Documentation-only.
}
