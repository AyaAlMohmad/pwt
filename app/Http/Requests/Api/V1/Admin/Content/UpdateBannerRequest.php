<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Services\Admin\AdminBannerService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request body for PUT /api/v1/admin/banners/{banner}.
 *
 * `code` is silently dropped via `prepareForValidation` because it is
 * immutable post-creation (docs/architecture/admin-content.md § 3.1.2 /
 * § 9.3). Bilingual fields fall back to existing stored translations when
 * only one locale is supplied (service-side merge in
 * {@see AdminBannerService::update()}).
 *
 * See docs/architecture/admin-content.md § 3.1.2.
 */
final class UpdateBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // `code` is immutable post-creation — silently drop.
        if ($this->has('code')) {
            $this->offsetUnset('code');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'placement' => ['nullable', 'string', 'in:hero,promo,sidebar,footer'],
            'title' => ['nullable', 'array'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'title.ar' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'array'],
            'subtitle.en' => ['nullable', 'string', 'max:500'],
            'subtitle.ar' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048', 'url'],
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
        ];
    }
}
