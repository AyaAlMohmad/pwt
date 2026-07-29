<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Models\Testimonial;

/**
 * Admin DETAIL shape for a {@see Testimonial}. Currently identical to the
 * list shape but retained as a distinct resource so future detail-only
 * fields can land here without churning the list shape.
 *
 * @property-read Testimonial $resource
 */
final class AdminTestimonialDetailResource extends AdminTestimonialResource
{
    // Inherits toArray() from AdminTestimonialResource — same shape for now.
}
