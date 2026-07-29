<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CancelOrderRequest',
    required: ['cancellation_reason'],
    properties: [
        new OA\Property(
            property: 'cancellation_reason',
            type: 'string',
            minLength: 5,
            maxLength: 500,
            example: 'Changed my mind',
        ),
    ],
    type: 'object',
)]
final class CancelOrderRequest
{
    // Documentation-only.
}
