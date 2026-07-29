<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Contact;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ContactLead',
    required: ['id', 'name', 'email', 'subject', 'source', 'type', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 481),
        new OA\Property(property: 'name', type: 'string', example: 'Layla Al-Mansour'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'layla@example.com'),
        new OA\Property(property: 'subject', type: 'string', example: 'Quote request for a 50LPH RO unit'),
        new OA\Property(property: 'source', type: 'string', enum: ['web', 'mobile', 'other'], example: 'web'),
        new OA\Property(property: 'type', type: 'string', enum: ['general', 'business'], example: 'general'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time',
            example: '2026-05-15T11:05:00+04:00'),
    ],
    type: 'object',
)]
final class ContactLead
{
    // Documentation-only.
}
