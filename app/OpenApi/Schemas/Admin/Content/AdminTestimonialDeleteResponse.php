<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Content;

use OpenApi\Attributes as OA;

/**
 * 200 OK envelope for DELETE /api/v1/admin/testimonials/{testimonial}.
 *
 * Documentation-only.
 */
#[OA\Schema(
    schema: 'AdminTestimonialDeleteResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Testimonial deleted.'),
        new OA\Property(property: 'data', ref: '#/components/schemas/AdminTestimonial'),
    ],
    type: 'object',
)]
final class AdminTestimonialDeleteResponse
{
    // Documentation-only.
}
