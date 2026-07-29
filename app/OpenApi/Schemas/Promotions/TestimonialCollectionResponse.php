<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Promotions;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TestimonialCollectionResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Testimonials retrieved.'),
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Testimonial'),
        ),
    ],
    type: 'object',
)]
final class TestimonialCollectionResponse
{
    // Documentation-only.
}
