<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

/**
 * Customer shape of a `testimonials` row.
 * See docs/architecture/promotions.md § 4.4.
 */
#[OA\Schema(
    schema: 'Testimonial',
    required: ['id', 'name', 'rating', 'quote', 'display_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Sara Al Mansouri'),
        new OA\Property(property: 'role', type: 'string', nullable: true, maxLength: 100, example: 'Office Manager'),
        new OA\Property(property: 'company', type: 'string', nullable: true, maxLength: 120, example: 'Acme Trading LLC'),
        new OA\Property(property: 'avatar_url', type: 'string', nullable: true, example: 'https://api.pwt.local/storage/testimonials/sara.jpg'),
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5, example: 5),
        new OA\Property(property: 'quote', type: 'string', example: "PWT's filtration system completely changed our office."),
        new OA\Property(property: 'display_order', type: 'integer', example: 0),
    ],
    type: 'object',
)]
final class Testimonial
{
    // Documentation-only.
}
