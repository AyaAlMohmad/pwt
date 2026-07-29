<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request body for POST /api/v1/admin/products/{product}/images.
 *
 * `file_ids` MUST each point at a non-trashed `files` row whose `type` is
 * `product_image`. Ownership is NOT checked at the request layer — any
 * admin with `products.manage-images` may attach any product_image
 * (admin-catalog.md § 9.3 / Option B).
 *
 * `alt_text` accepts the bilingual `{ "en": "...", "ar": "..." }` shape
 * with BOTH locales optional (the admin may seed alt text in a single
 * locale and fill the other later).
 *
 * `is_primary=true` triggers an atomic demotion of any existing primary
 * inside the service and sets the FIRST attached image as primary.
 *
 * See docs/architecture/admin-catalog.md § 3.6.
 */
final class AttachProductImageRequest extends FormRequest
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
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => [
                'integer',
                Rule::exists('files', 'id')
                    ->where('type', 'product_image')
                    ->whereNull('deleted_at'),
            ],
            'alt_text' => ['nullable', 'array'],
            'alt_text.en' => ['nullable', 'string', 'max:255'],
            'alt_text.ar' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_id.exists' => __('admin.catalog.image_invalid_file_type'),
        ];
    }
}
