<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Catalog;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/products — query-string validation.
 *
 * Per docs/architecture/catalog.md § 3.3, the customer cannot pass
 * `status=`; the visibility scope is server-side. The `term`, `sort`,
 * and `order` parameters are normalised to lowercase in
 * {@see prepareForValidation()} so the `in:` rules behave predictably
 * regardless of casing on the wire.
 *
 * Cross-field rule: `max_price >= min_price` when BOTH are provided.
 * Implemented in {@see withValidator()} rather than via the built-in
 * `gte:min_price` rule because Laravel's `gte` triggers a validation
 * failure when the compared field is missing — but the spec (§ 3.3)
 * states the check applies only when both values are sent. A client
 * may legitimately pass `?max_price=400` alone.
 *
 * The `featured` query parameter is coerced from common string forms
 * (`"true"`, `"false"`, `"yes"`, `"no"`, `""`) to a real boolean in
 * {@see prepareForValidation()} so the `boolean` rule accepts the
 * forms naturally produced by query strings. `category_id` must exist
 * AND be `is_active=true`; the failure is raised through a closure
 * rule for a precise error message rather than the column-condition
 * `exists:` shortcut.
 */
final class ListProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Lowercase the case-insensitive enum-style filters before
     * validation so `Buy`, `BUY`, and `buy` are all accepted.
     *
     * Also coerces the `featured` query parameter from string forms
     * commonly produced by URL builders (`"true"`, `"false"`, `"yes"`,
     * `"no"`, `""`) to a real boolean. Laravel's `boolean` rule itself
     * accepts only `1/0/"1"/"0"/true/false`, so without this coercion a
     * caller hitting `?featured=true` would get a 422. Garbage values
     * are left intact for the validator to reject.
     */
    protected function prepareForValidation(): void
    {
        $patches = [];

        foreach (['term', 'sort', 'order'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && $value !== '') {
                $patches[$key] = strtolower($value);
            }
        }

        if ($this->has('currency')) {
            $currency = $this->input('currency');
            if (is_string($currency) && $currency !== '') {
                $patches['currency'] = strtoupper($currency);
            }
        }

        if ($this->has('featured')) {
            $featured = $this->input('featured');
            if (is_string($featured)) {
                $lower = strtolower($featured);
                $patches['featured'] = match ($lower) {
                    'true', '1', 'yes' => true,
                    'false', '0', 'no', '' => false,
                    default => $featured, // leave for the validator to reject
                };
            }
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
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1', $this->activeCategoryRule()],
            'term' => ['nullable', 'string', 'in:buy,rent,foc'],
            'search' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            // The cross-field `max_price >= min_price` check lives in
            // withValidator(): the built-in `gte:min_price` rule fails
            // when min_price is absent, but the spec (§ 3.3) allows
            // sending only one bound.
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'in:AED,SAR,QAR,KWD,BHD,OMR'],
            'sort' => ['nullable', 'string', 'in:price,created_at,name'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
            'featured' => ['nullable', 'boolean'],
            'tag_label' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.min' => __('catalog.invalid_filters'),
            'term.in' => __('catalog.invalid_filters'),
            'currency.in' => __('catalog.invalid_filters'),
            'sort.in' => __('catalog.invalid_filters'),
            'order.in' => __('catalog.invalid_filters'),
        ];
    }

    /**
     * Cross-field validation: enforce `max_price >= min_price` only
     * when BOTH bounds are supplied. Using `after()` (rather than the
     * built-in `gte:` rule) avoids a false positive when the caller
     * sends only `max_price` — a legitimate use case per § 3.3.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('min_price') || ! $this->filled('max_price')) {
                return;
            }

            $min = $this->input('min_price');
            $max = $this->input('max_price');

            if (! is_numeric($min) || ! is_numeric($max)) {
                return; // numeric/min rules will catch this separately
            }

            if ((float) $max < (float) $min) {
                $v->errors()->add('max_price', __('catalog.invalid_filters'));
            }
        });
    }

    /**
     * Closure rule asserting `categories.id = ? AND is_active = TRUE`.
     *
     * Using a closure (instead of `exists:categories,id,is_active,1`)
     * lets the framework raise a translated message and keeps the
     * validation output consistent with the rest of the module.
     */
    private function activeCategoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_numeric($value)) {
                return;
            }

            $exists = DB::table('categories')
                ->whereNull('deleted_at')
                ->where('id', (int) $value)
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                $fail(__('catalog.invalid_filters'));
            }
        };
    }
}
