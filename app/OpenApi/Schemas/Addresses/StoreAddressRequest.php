<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreAddressRequest',
    description: 'Create a new address. `is_default=true` atomically demotes any previous default. The 11th address per customer is rejected with 422 ERR_ADDRESS_LIMIT_REACHED.',
    required: [
        'label', 'country_id', 'city_id', 'line1',
    ],
    properties: [
        new OA\Property(property: 'label', type: 'string', maxLength: 60, example: 'Home'),
        new OA\Property(property: 'country_id', type: 'integer', example: 1, description: 'Must reference an active country.'),
        new OA\Property(property: 'city_id', type: 'integer', example: 4, description: 'Required; must belong to country_id (references the regions table).'),
        new OA\Property(property: 'recipient_name', type: 'string', maxLength: 120, nullable: true, example: 'Ahmed Saleh'),
        new OA\Property(property: 'recipient_phone', type: 'string', maxLength: 20, nullable: true, example: '501234567', description: 'Local number (combined with recipient_phone_country_code) or E.164.'),
        new OA\Property(property: 'recipient_phone_country_code', type: 'string', maxLength: 6, nullable: true, example: '+971'),
        new OA\Property(property: 'line1', type: 'string', maxLength: 200, example: 'Villa 42, Street 5'),
        new OA\Property(property: 'line2', type: 'string', maxLength: 200, nullable: true, example: null),
        new OA\Property(property: 'city', type: 'string', maxLength: 100, nullable: true, example: 'Dubai'),
        new OA\Property(property: 'area', type: 'string', maxLength: 100, nullable: true, example: 'Jumeirah 1'),
        new OA\Property(property: 'postal_code', type: 'string', maxLength: 20, nullable: true, example: null),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, example: 'Gate code 4521'),
        new OA\Property(property: 'is_default', type: 'boolean', example: true, default: false),
        new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 25.2048),
        new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 55.2708),
    ],
    type: 'object',
)]
final class StoreAddressRequest
{
    // Documentation-only.
}
