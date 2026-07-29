<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PublicSettings',
    required: [
        'supported_locales',
        'default_locale',
        'currencies_by_country',
        'vat_rates_by_country',
        'whatsapp_number',
        'contact_email',
        'social_links',
        'support_hours',
    ],
    properties: [
        new OA\Property(
            property: 'supported_locales',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'en'),
            example: ['en', 'ar'],
        ),
        new OA\Property(property: 'default_locale', type: 'string', example: 'en'),
        new OA\Property(
            property: 'currencies_by_country',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'string'),
            example: ['ae' => 'AED', 'sa' => 'SAR', 'qa' => 'QAR', 'kw' => 'KWD', 'bh' => 'BHD', 'om' => 'OMR'],
        ),
        new OA\Property(
            property: 'vat_rates_by_country',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'number', format: 'float'),
            example: ['ae' => 5.00, 'sa' => 15.00, 'qa' => 0.00, 'kw' => 0.00, 'bh' => 10.00, 'om' => 5.00],
        ),
        new OA\Property(property: 'whatsapp_number', type: 'string', example: '+971500000000'),
        new OA\Property(property: 'contact_email', type: 'string', format: 'email', example: 'support@pwt.local'),
        new OA\Property(
            property: 'social_links',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true),
            example: ['facebook' => null, 'instagram' => null, 'linkedin' => null],
        ),
        new OA\Property(property: 'support_hours', type: 'string', example: 'Sun–Thu 9:00–18:00 GST'),
    ],
    type: 'object',
)]
final class PublicSettings
{
    // Documentation-only.
}
