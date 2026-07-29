<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request body for POST /api/v1/admin/banners.
 *
 * Bilingual fields use the `{ en, ar }` shape per CLAUDE.md § 8. `code` is
 * unique and immutable post-creation (see {@see UpdateBannerRequest}).
 *
 * See docs/architecture/admin-content.md § 3.1.1.
 */
final class CreateBannerRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('banners', 'code')],
            'placement' => ['required', 'string', 'in:hero,promo,sidebar,footer'],
            'title' => ['nullable', 'array'],
            'title.en' => ['required_with:title', 'string', 'max:255'],
            'title.ar' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'array'],
            'subtitle.en' => ['nullable', 'string', 'max:500'],
            'subtitle.ar' => ['nullable', 'string', 'max:500'],
            'image_url' => ['required', 'string', 'max:2048', 'url'],
            'image_url_ar' => ['nullable', 'string', 'max:2048', 'url'],
            'cta_label' => ['nullable', 'array'],
            'cta_label.en' => ['nullable', 'string', 'max:80'],
            'cta_label.ar' => ['nullable', 'string', 'max:80'],
            'cta_url' => ['nullable', 'string', 'max:2048', 'url'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => __('admin.banners.date_range_invalid'),
            'code.unique' => __('admin.banners.duplicate_code'),
        ];
    }
}
