<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\User;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeleteAccountResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Your account has been deleted.'),
        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
    ],
    type: 'object',
)]
final class DeleteAccountResponse
{
    // Documentation-only.
}
