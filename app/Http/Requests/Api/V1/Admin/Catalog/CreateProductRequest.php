<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Request body for POST /api/v1/admin/products.
 *
 * Accepts inline `prices: [...]` and `specs: [...]` arrays — these are
 * inserted atomically alongside the product (Phase 16 § 4.3 / § 3.5.1).
 * Cross-field rules — `prices.*.term=rent` requires `period` and
 * `min_term_months`; duplicate (term, currency) tuples are rejected;
 * quote-only + non-empty prices is mutually exclusive — are enforced in
 * `withValidator()`.
 *
 * `images: [...]` is rejected because images use the dedicated attach
 * endpoint (§ 3.5).
 *
 * See docs/architecture/admin-catalog.md § 3.5.
 */
final class CreateProductRequest extends FormRequest
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
                Rule::unique('products', 'code'),
            ],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'min:2', 'max:200'],
            'name.ar' => ['nullable', 'string', 'min:2', 'max:200'],
            'short_description' => ['nullable', 'array'],
            'short_description.en' => ['nullable', 'string', 'max:500'],
            'short_description.ar' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:10000'],
            'description.ar' => ['nullable', 'string', 'max:10000'],
            'benefits' => ['nullable', 'array'],
            'benefits.en' => ['nullable', 'array', 'max:50'],
            'benefits.en.*' => ['string', 'max:200'],
            'benefits.ar' => ['nullable', 'array', 'max:50'],
            'benefits.ar.*' => ['string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_quote_only' => ['nullable', 'boolean'],
            'individual_buy_enabled' => ['nullable', 'boolean'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'original_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'tag_label' => ['nullable', 'string', 'max:30'],
            'rent_stars' => ['nullable', 'integer', 'min:1', 'max:5'],
            'is_machine_eligible' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,out_of_stock,coming_soon,discontinued'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'prices' => ['nullable', 'array', 'max:24'],
            'prices.*.term' => ['required', 'string', 'in:buy,rent,foc'],
            'prices.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'prices.*.currency' => ['required', 'string', 'size:3', 'in:AED,SAR,QAR,KWD,BHD,OMR'],
            'prices.*.period' => ['nullable', 'string', 'in:monthly,yearly'],
            'prices.*.min_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'prices.*.is_active' => ['nullable', 'boolean'],

            'specs' => ['nullable', 'array', 'max:50'],
            'specs.*.label' => ['required', 'array'],
            'specs.*.label.en' => ['required', 'string', 'min:1', 'max:120'],
            'specs.*.label.ar' => ['nullable', 'string', 'min:1', 'max:120'],
            'specs.*.value' => ['required', 'array'],
            'specs.*.value.en' => ['required', 'string', 'min:1', 'max:200'],
            'specs.*.value.ar' => ['nullable', 'string', 'min:1', 'max:200'],
            'specs.*.display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],

            'images' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validatePriceCrossFields($v);
            $this->validateQuoteOnlyMatrix($v);
        });
    }

    private function validatePriceCrossFields(Validator $v): void
    {
        $prices = $this->input('prices');
        if (! is_array($prices)) {
            return;
        }

        $seen = [];
        foreach ($prices as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $term = $row['term'] ?? null;
            $currency = $row['currency'] ?? null;
            $period = $row['period'] ?? null;
            $min = $row['min_term_months'] ?? null;

            if ($term === 'rent') {
                if ($period === null || $period === '') {
                    $v->errors()->add("prices.$idx.period", 'The period is required when term is rent.');
                }
                if ($min === null || $min === '') {
                    $v->errors()->add("prices.$idx.min_term_months", 'The min_term_months is required when term is rent.');
                }
            } else {
                if ($period !== null && $period !== '') {
                    $v->errors()->add("prices.$idx.period", 'The period must be null when term is not rent.');
                }
                if ($min !== null && $min !== '') {
                    $v->errors()->add("prices.$idx.min_term_months", 'The min_term_months must be null when term is not rent.');
                }
            }

            if (is_string($term) && is_string($currency)) {
                $key = $term.'|'.strtoupper($currency);
                if (isset($seen[$key])) {
                    $v->errors()->add(
                        'prices',
                        "Duplicate term+currency combination at indices {$seen[$key]} and $idx.",
                    );
                } else {
                    $seen[$key] = $idx;
                }
            }
        }
    }

    private function validateQuoteOnlyMatrix(Validator $v): void
    {
        $prices = $this->input('prices');
        $isQuoteOnly = $this->boolean('is_quote_only');

        // Empty prices array + not quote-only → invalid (cannot sell).
        if (is_array($prices) && $prices === [] && ! $isQuoteOnly) {
            $v->errors()->add('is_quote_only', 'Products without prices must be quote-only.');
        }

        // quote_only=true + non-empty prices → invalid.
        if ($isQuoteOnly && is_array($prices) && $prices !== []) {
            $v->errors()->add('prices', 'Quote-only products must not have prices.');
        }
    }
}
