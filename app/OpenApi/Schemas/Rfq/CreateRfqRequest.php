<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Rfq;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CreateRfqRequest',
    type: 'object',
    required: ['company_name', 'email'],
    properties: [
        new OA\Property(property: 'company_name', type: 'string', maxLength: 200, example: 'Acme Facilities Ltd'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 200, example: 'ahmed@company.com'),
        new OA\Property(property: 'phone', type: 'string', maxLength: 30, nullable: true, example: '501234567'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 5000, nullable: true, example: 'Multi-site rollout with branding.'),
        new OA\Property(property: 'website', type: 'string', maxLength: 255, nullable: true, description: 'Honeypot field — leave empty.'),
    ],
)]
final class CreateRfqRequest
{
    // Documentation-only.
}
