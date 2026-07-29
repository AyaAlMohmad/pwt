<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PUT /api/v1/admin/testimonials/{testimonial}.
 *
 * Every field optional; service merges translatable `quote` against the
 * existing translations when only one locale is supplied.
 *
 * See docs/architecture/admin-content.md § 3.3.2.
 */
final class UpdateTestimonialRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'string', 'max:2048', 'url'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'quote' => ['nullable', 'array'],
            'quote.en' => ['nullable', 'string', 'max:2000'],
            'quote.ar' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
