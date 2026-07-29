<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Contact;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ContactLeadResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Thank you, we will be in touch.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/ContactLead'),
    ],
    type: 'object',
)]
final class ContactLeadResponse
{
    // Documentation-only.
}
