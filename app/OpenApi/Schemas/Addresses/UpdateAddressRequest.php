<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAddressRequest',
    description: 'Partial update; every field is optional. Omitted fields are unchanged. Explicit-null clears nullable fields (line2, city_id, area, postal_code, notes, latitude, longitude).',
    properties: [
        new OA\Property(property: 'label', type: 'string', maxLength: 60, example: 'Office'),
        new OA\Property(property: 'country_id', type: 'integer', example: 1),
        new OA\Property(property: 'city_id', type: 'integer', nullable: true, example: 4),
        new OA\Property(property: 'recipient_name', type: 'string', maxLength: 120, nullable: true, example: 'Ahmed Saleh'),
        new OA\Property(property: 'recipient_phone', type: 'string', maxLength: 20, nullable: true, example: '501234567'),
        new OA\Property(property: 'recipient_phone_country_code', type: 'string', maxLength: 6, nullable: true, example: '+971'),
        new OA\Property(property: 'line1', type: 'string', maxLength: 200, example: 'Villa 42'),
        new OA\Property(property: 'line2', type: 'string', maxLength: 200, nullable: true, example: 'Building B'),
        new OA\Property(property: 'city', type: 'string', maxLength: 100, nullable: true, example: 'Dubai'),
        new OA\Property(property: 'area', type: 'string', maxLength: 100, nullable: true, example: 'Downtown'),
        new OA\Property(property: 'postal_code', type: 'string', maxLength: 20, nullable: true, example: '00000'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, example: 'Ring twice'),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
        new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 25.2048),
        new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 55.2708),
    ],
    type: 'object',
)]
final class UpdateAddressRequest
{
    // Documentation-only.
}
