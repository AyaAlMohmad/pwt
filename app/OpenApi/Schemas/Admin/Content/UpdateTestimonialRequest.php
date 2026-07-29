<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * Request body for PUT /api/v1/admin/testimonials/{testimonial}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'UpdateTestimonialRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 120),
        new OA\Property(property: 'role', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'company', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'avatar_url', type: 'string', maxLength: 2048, format: 'uri', nullable: true),
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(
            property: 'quote',
            type: 'object',
            properties: [
                new OA\Property(property: 'en', type: 'string', maxLength: 2000, nullable: true),
                new OA\Property(property: 'ar', type: 'string', maxLength: 2000, nullable: true),
            ],
        ),
        new OA\Property(property: 'display_order', type: 'integer', minimum: 0, maximum: 9999),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ],
    type: 'object',
)]
final class UpdateTestimonialRequest
{
    // Documentation-only.
}
