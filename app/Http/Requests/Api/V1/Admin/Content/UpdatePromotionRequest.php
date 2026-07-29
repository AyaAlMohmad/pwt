<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Request body for PUT /api/v1/admin/promotions/{promotion}.
 *
 * `code` is silently dropped via `prepareForValidation` — immutable
 * post-creation (docs/architecture/admin-content.md § 9.3). The other
 * cross-field rules mirror {@see CreatePromotionRequest}.
 *
 * See docs/architecture/admin-content.md § 3.2.2.
 */
final class UpdatePromotionRequest extends FormRequest
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
        if ($this->has('currency') && is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper((string) $this->input('currency'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'array'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'description.ar' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'string', 'in:percent,fixed'],
            'value' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3', 'in:AED,SAR,QAR,KWD,BHD,OMR'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'total_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'applies_to' => ['nullable', 'string', 'in:all,products,categories'],
            'product_ids' => ['array', 'required_if:applies_to,products', 'prohibited_unless:applies_to,products'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['array', 'required_if:applies_to,categories', 'prohibited_unless:applies_to,categories'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $type = $this->input('type');
            $value = $this->input('value');
            if ($type === DiscountType::Percent->value && $value !== null && (float) $value > 100.0) {
                $v->errors()->add('value', __('admin.promotions.percent_over_hundred'));
            }

            if ($type === DiscountType::Fixed->value && $this->filled('max_discount_amount')) {
                $v->errors()->add(
                    'max_discount_amount',
                    __('admin.promotions.max_discount_only_for_percent'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => __('admin.promotions.date_range_invalid'),
        ];
    }
}
