<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * 200 OK envelope for DELETE /api/v1/admin/promotions/{promotion}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminPromotionDeleteResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Promotion deleted.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminPromotionDetail'),
    ],
    type: 'object',
)]
final class AdminPromotionDeleteResponse
{
    // Documentation-only.
}
