<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Addresses;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Address',
    required: [
        'id', 'label', 'recipient_name', 'recipient_phone',
        'recipient_phone_country_code', 'line1', 'city',
        'is_default', 'created_at', 'updated_at',
    ],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 17, description: 'Stable cross-module identifier (used as orders.delivery_address_id from Phase 8).'),
        new OA\Property(property: 'label', type: 'string', maxLength: 60, example: 'Home'),
        new OA\Property(property: 'recipient_name', type: 'string', maxLength: 120, example: 'Ahmed Saleh'),
        new OA\Property(property: 'recipient_phone', type: 'string', maxLength: 20, example: '+971501234567'),
        new OA\Property(property: 'recipient_phone_country_code', type: 'string', maxLength: 6, example: '+971'),
        new OA\Property(property: 'line1', type: 'string', maxLength: 200, example: 'Villa 42, Street 5'),
        new OA\Property(property: 'line2', type: 'string', maxLength: 200, nullable: true, example: 'Building B'),
        new OA\Property(property: 'city', type: 'string', maxLength: 100, example: 'Dubai'),
        new OA\Property(property: 'area', type: 'string', maxLength: 100, nullable: true, example: 'Jumeirah 1'),
        new OA\Property(property: 'postal_code', type: 'string', maxLength: 20, nullable: true, example: null),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true, example: 'Gate code 4521'),
        new OA\Property(property: 'country', ref: '#/components/schemas/Country', nullable: true),
        new OA\Property(property: 'region', ref: '#/components/schemas/Region', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean', example: true),
        new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, example: 25.2048),
        new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, example: 55.2708),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-10T09:00:00+04:00'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-05-14T11:30:00+04:00'),
    ],
    type: 'object',
)]
final class Address
{
    // Documentation-only.
}
