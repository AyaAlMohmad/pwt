<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Request body for POST /api/v1/admin/promotions.
 *
 * Cross-field rules enforced in `withValidator`:
 *  - percent ≤ 100
 *  - max_discount_amount must be null when type=fixed
 *
 * `code` is uppercased + trimmed in `prepareForValidation` so the
 * `unique:promotions,code` rule sees the canonical form and the service
 * stores the same normalised string (matches `PromotionEngine::evaluate()`
 * lookup contract).
 *
 * See docs/architecture/admin-content.md § 3.2.1.
 */
final class CreatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
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
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promotions', 'code')],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:255'],
            'name.ar' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:2000'],
            'description.ar' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'string', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2', 'required_if:type,percent'],
            'currency' => ['nullable', 'string', 'size:3', 'in:AED,SAR,QAR,KWD,BHD,OMR', 'required_if:type,fixed'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'total_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'applies_to' => ['required', 'string', 'in:all,products,categories'],
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

            // max_discount_amount must be null when type=fixed (already
            // required_if:type,percent — this rejects fixed + cap together).
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
            'code.unique' => __('admin.promotions.duplicate_code'),
            'code.regex' => __('admin.promotions.code_format'),
        ];
    }
}
