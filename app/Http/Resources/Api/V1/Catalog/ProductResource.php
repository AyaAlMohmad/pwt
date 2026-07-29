<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact product shape used by the paginated list endpoint
 * (`GET /api/v1/products`).
 *
 * `starting_price` is computed from the eager-loaded `activePrices`
 * relation (filtered by the resolved currency, then by min amount).
 * `primary_image_url` is computed from the eager-loaded `images`
 * relation (is_primary first, then lowest display_order).
 *
 * @property-read Product $resource
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $locale = app()->getLocale();

        // Resolved currency lives on the request as a custom attribute
        // (set by the controller after the Form Request applies its
        // default). Falls back to AED when the controller did not set it.
        $currency = $this->resolveCurrency($request);
        $term = $this->resolveTerm($request);

        $startingPrice = $this->resolveStartingPrice($product, $currency, $term);
        $discount = $product->discount_percentage;
        $originalPrice = $product->original_price;

        $finalPrice = null;
        if ($originalPrice !== null && $discount !== null && (float) $discount > 0) {
            $finalPrice = (string) round((float) $originalPrice * (1 - (float) $discount / 100), 2);
        }

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->getTranslation('name', $locale),
            'short_description' => $product->getTranslation('short_description', $locale, false) ?: null,
            'primary_image_url' => $this->resolvePrimaryImageUrl($product, $request),
            'starting_price' => $startingPrice,
            'original_price' => $originalPrice !== null ? (string) $originalPrice : null,
            'discount_percentage' => $discount !== null ? (float) $discount : null,
            'final_price' => $finalPrice,
            'tag_label' => $product->tag_label,
            'rent_stars' => $product->rent_stars !== null ? (int) $product->rent_stars : null,
            'category' => $product->relationLoaded('category') && $product->category !== null
                ? (new CategoryResource($product->category))->resolve($request)
                : null,
            'is_quote_only' => (bool) $product->is_quote_only,
            'individual_buy_enabled' => (bool) $product->individual_buy_enabled,
            'stock_status' => $product->stock_status->value,
            'is_featured' => (bool) $product->is_featured,
            'created_at' => $product->created_at->toIso8601String(),
        ];
    }

    private function resolveCurrency(Request $request): string
    {
        $value = $request->attributes->get('catalog.currency');
        if (is_string($value) && $value !== '') {
            return strtoupper($value);
        }

        $query = $request->query('currency');

        return is_string($query) && $query !== ''
            ? strtoupper($query)
            : 'AED';
    }

    /**
     * Resolve the term filter from the request attributes. Null when
     * the customer did not filter by term — the starting price then
     * falls back to "lowest amount across any term".
     */
    private function resolveTerm(Request $request): ?string
    {
        $value = $request->attributes->get('catalog.term');

        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    private function resolvePrimaryImageUrl(Product $product, Request $request): ?string
    {
        if (! $product->relationLoaded('images')) {
            return null;
        }

        /** @var ProductImage|null $primary */
        $primary = $product->images->first(fn (ProductImage $image): bool => $image->is_primary === true)
            ?? $product->images->first();

        if ($primary === null) {
            return null;
        }

        return $this->resolveImageUrl($primary->url, $request);
    }

    private function resolveImageUrl(?string $path, Request $request): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $root = rtrim($request->getSchemeAndHttpHost(), '/');

        return $root . '/storage/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStartingPrice(Product $product, string $currency, ?string $term): ?array
    {
        if (! $product->relationLoaded('activePrices')) {
            return null;
        }

        $rows = $product->activePrices->where('currency', $currency);
        if ($term !== null) {
            $rows = $rows->where('term', $term);
        }

        /** @var ProductPrice|null $row */
        $row = $rows
            ->sortBy(fn (ProductPrice $p): float => (float) $p->amount)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'term' => $row->term->value,
            'amount' => (string) $row->amount,
            'currency' => $row->currency,
            'period' => $row->period,
            'min_term_months' => $row->min_term_months !== null ? (int) $row->min_term_months : null,
        ];
    }
}
