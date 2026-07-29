<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PromotionValidationResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Promotion validated.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/PromotionValidationResult'),
    ],
    type: 'object',
)]
final class PromotionValidationResponse
{
    // Documentation-only.
}
