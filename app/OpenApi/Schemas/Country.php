<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Country',
    required: ['code', 'name', 'phone_code', 'currency', 'vat_rate', 'is_active'],
    properties: [
        new OA\Property(property: 'code', type: 'string', minLength: 2, maxLength: 2, example: 'ae'),
        new OA\Property(property: 'name', type: 'string', example: 'United Arab Emirates'),
        new OA\Property(property: 'phone_code', type: 'string', example: '+971'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'AED'),
        new OA\Property(property: 'vat_rate', type: 'number', format: 'float', example: 5.00),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
    ],
    type: 'object',
)]
final class Country
{
    // Documentation-only.
}
