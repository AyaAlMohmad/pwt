<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/admin/testimonials.
 *
 * Only `quote` is translatable; `name`, `role`, `company` are plain
 * strings (matches Phase 7 model `$translatable = ['quote']`).
 *
 * See docs/architecture/admin-content.md § 3.3.1.
 */
final class CreateTestimonialRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'string', 'max:2048', 'url'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'quote' => ['required', 'array'],
            'quote.en' => ['required', 'string', 'max:2000'],
            'quote.ar' => ['nullable', 'string', 'max:2000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
