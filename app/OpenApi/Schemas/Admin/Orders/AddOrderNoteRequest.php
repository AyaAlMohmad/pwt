<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Orders;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AddOrderNoteRequest',
    required: ['note'],
    properties: [
        new OA\Property(property: 'note', type: 'string', minLength: 3, maxLength: 2000, example: 'Spoke with customer; they will be home Friday between 10am-2pm'),
    ],
    type: 'object',
)]
final class AddOrderNoteRequest
{
    // Documentation-only.
}
