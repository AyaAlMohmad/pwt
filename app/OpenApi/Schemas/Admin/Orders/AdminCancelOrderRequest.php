<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminCancelOrderRequest',
    required: ['reason'],
    properties: [
        new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 1000, example: 'Customer rejected delivery at door'),
    ],
    type: 'object',
)]
final class AdminCancelOrderRequest
{
    // Documentation-only.
}
