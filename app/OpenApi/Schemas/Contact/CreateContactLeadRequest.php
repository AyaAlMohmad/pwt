<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Contact;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateContactLeadRequest',
    required: ['name', 'email', 'subject', 'message'],
    properties: [
        new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 120,
            example: 'Layla Al-Mansour'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 200,
            example: 'layla@example.com'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, maxLength: 30,
            example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', nullable: true, maxLength: 6,
            example: '+971'),
        new OA\Property(property: 'subject', type: 'string', minLength: 3, maxLength: 200,
            example: 'Quote request for a 50LPH RO unit'),
        new OA\Property(property: 'message', type: 'string', minLength: 10, maxLength: 5000,
            example: 'Hello, please share pricing and availability for a 50LPH RO unit. Thank you.'),
        new OA\Property(property: 'source', type: 'string', enum: ['web', 'mobile', 'other'], default: 'web',
            example: 'web',
            description: 'Channel that produced the submission. Defaults to `web`.'),
        new OA\Property(property: 'type', type: 'string', enum: ['general', 'business'], default: 'general',
            example: 'general',
            description: 'Inquiry type. Defaults to `general`.'),
    ],
    type: 'object',
)]
final class CreateContactLeadRequest
{
    // Documentation-only.
}
