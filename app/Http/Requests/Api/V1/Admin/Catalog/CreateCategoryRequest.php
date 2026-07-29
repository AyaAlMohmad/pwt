<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for POST /api/v1/admin/categories.
 *
 * Bilingual `name` / `description` use the `{ "en": "...", "ar": "..." }`
 * object form. EN is mandatory on required translatable fields (fallback
 * locale); AR is optional and falls back to EN when omitted (per Spatie
 * `HasTranslations` semantics).
 *
 * Policy authorisation (`Gate::authorize('create', Category::class)`) is
 * asserted by the controller. This request validates shape only.
 *
 * See docs/architecture/admin-catalog.md § 3.2.
 */
final class CreateCategoryRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'min:2',
                'max:80',
                'regex:/^[a-z0-9-]+$/',
                'unique:categories,code',
            ],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'min:2', 'max:120'],
            'name.ar' => ['nullable', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:5000'],
            'description.ar' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code must contain only lowercase letters, digits and hyphens.',
            'name.required' => 'The name field is required (bilingual object expected).',
            'name.array' => 'The name field must be an object with at least an "en" key.',
            'name.en.required' => 'The English (en) translation is required for the name.',
        ];
    }
}
