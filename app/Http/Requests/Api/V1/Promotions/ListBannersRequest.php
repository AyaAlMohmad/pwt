<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Promotions;

use App\Enums\BannerPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/banners — query-string validation.
 *
 * The only param is `?placement=hero|promo|sidebar|footer`. The value is
 * lowercased in {@see prepareForValidation()} so casing on the wire is
 * accepted (docs/architecture/promotions.md § 3.1).
 */
final class ListBannersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $placement = $this->input('placement');
        if (is_string($placement) && $placement !== '') {
            $this->merge(['placement' => strtolower($placement)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'placement' => ['nullable', 'string', Rule::in(BannerPlacement::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'placement.in' => __('promotions.invalid_filters'),
        ];
    }
}
