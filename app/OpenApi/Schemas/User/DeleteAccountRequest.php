<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\User;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeleteAccountRequest',
    required: ['password', 'confirm'],
    properties: [
        new OA\Property(property: 'password', type: 'string', example: 'Password123!'),
        new OA\Property(property: 'confirm', type: 'string', enum: ['DELETE'], example: 'DELETE', description: 'Must equal the literal string "DELETE" to confirm intent.'),
    ],
    type: 'object',
)]
final class DeleteAccountRequest
{
    // Documentation-only.
}
