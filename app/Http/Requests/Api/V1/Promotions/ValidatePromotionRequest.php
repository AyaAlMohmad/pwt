<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Promotions;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/promotions/validate — body validation.
 *
 * Per docs/architecture/promotions.md § 3.3:
 *   - `code` is uppercased + trimmed in {@see prepareForValidation()}.
 *   - `currency` is uppercased to match the canonical stored form.
 *   - `subtotal` is the cart's pre-discount total (server NEVER trusts
 *     this for order totals — Phase 8 recomputes from `order_items`).
 *   - `product_ids` / `category_ids` are bounded at 50 entries to defeat
 *     enumeration-style probes.
 */
#[OA\RequestBody(
    request: 'PromotionValidationRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/PromotionValidationRequest'),
)]
final class ValidatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $patches = [];

        $code = $this->input('code');
        if (is_string($code)) {
            $patches['code'] = strtoupper(trim($code));
        }

        $currency = $this->input('currency');
        if (is_string($currency) && $currency !== '') {
            $patches['currency'] = strtoupper($currency);
        }

        if ($patches !== []) {
            $this->merge($patches);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'subtotal' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'currency' => ['required', 'string', 'size:3', 'in:AED,SAR,QAR,KWD,BHD,OMR'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['integer', 'min:1', 'exists:products,id'],
            'category_ids' => ['nullable', 'array', 'max:50'],
            'category_ids.*' => ['integer', 'min:1', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('promotions.code_required'),
            'subtotal.required' => __('promotions.subtotal_required'),
            'subtotal.numeric' => __('promotions.subtotal_invalid'),
            'subtotal.min' => __('promotions.subtotal_invalid'),
            'currency.required' => __('promotions.currency_required'),
            'currency.in' => __('promotions.currency_invalid'),
            'product_ids.array' => __('promotions.invalid_filters'),
            'product_ids.*.integer' => __('promotions.invalid_filters'),
            'product_ids.*.exists' => __('promotions.invalid_filters'),
            'category_ids.array' => __('promotions.invalid_filters'),
            'category_ids.*.integer' => __('promotions.invalid_filters'),
            'category_ids.*.exists' => __('promotions.invalid_filters'),
        ];
    }
}
