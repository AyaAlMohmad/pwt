<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/testimonials.
 *
 * Permission-level authorisation (`testimonials.view`) is asserted by
 * the controller via `$this->authorize('viewAny', Testimonial::class)`.
 *
 * See docs/architecture/admin-content.md § 1.
 */
final class ListAdminTestimonialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'min:1', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_min' => ['nullable', 'integer', 'min:1', 'max:5'],
            'rating_max' => ['nullable', 'integer', 'min:1', 'max:5'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:display_order,rating,created_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
