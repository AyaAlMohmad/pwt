<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Rfq;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminRfqDetailResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'OK'),
        new OA\Property(
            property: 'data',
            ref: '#/components/schemas/AdminRfqResponse',
        ),
    ],
)]
final class AdminRfqDetailResponse
{
    // Documentation-only.
}
