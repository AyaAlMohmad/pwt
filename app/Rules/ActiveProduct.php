<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\StockStatus;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a product id refers to an active, in-stock,
 * non-soft-deleted, non-quote-only product.
 *
 * Used per-line on `POST /api/v1/orders`. A soft / structured failure
 * (out of stock, coming soon, quote-only) is re-mapped to
 * `ERR_PRODUCT_UNAVAILABLE` by the service layer; this rule short-
 * circuits with a generic ERR_VALIDATION 422 for the obvious cases
 * (missing id / inactive product) so the engine does not even start.
 */
final class ActiveProduct implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        /** @var Product|null $product */
        $product = Product::query()
            ->where('id', (int) $value)
            ->first();

        if ($product === null) {
            $fail(__('orders.product_not_found'));

            return;
        }

        if (! $product->is_active) {
            $fail(__('orders.product_inactive'));

            return;
        }

        if ($product->stock_status !== StockStatus::InStock) {
            $fail(__('orders.product_out_of_stock'));

            return;
        }

        if ($product->is_quote_only) {
            $fail(__('orders.product_quote_only'));
        }
    }
}
