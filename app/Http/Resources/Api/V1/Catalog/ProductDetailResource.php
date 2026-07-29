<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Promotions\PromotionEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full product detail shape used by `GET /api/v1/products/{id}`.
 *
 * Unlike the list resource, the detail endpoint returns ALL active
 * price rows across ALL currencies / terms so the client UI can render
 * the per-currency / per-term toggle without a second request.
 * `promotions_count` was a Phase 6 placeholder (literal 0); Phase 7 wires
 * the real count via {@see PromotionEngine::countActiveForProduct()}
 * (docs/architecture/promotions.md § 7).
 *
 * @property-read Product $resource
 */
final class ProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $locale = app()->getLocale();

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
            'description' => $product->getTranslation('description', $locale, false) ?: null,
            'benefits' => $this->resolveBenefits($product, $locale),
            'primary_image_url' => $this->resolvePrimaryImageUrl($product, $request),
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'specs' => ProductSpecResource::collection($this->whenLoaded('specs')),
            'prices' => ProductPriceResource::collection($this->whenLoaded('activePrices')),
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
            'promotions_count' => app(PromotionEngine::class)
                ->countActiveForProduct($product->id, $product->category_id),
            'created_at' => $product->created_at->toIso8601String(),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function resolveBenefits(Product $product, string $locale): ?array
    {
        $value = $product->getTranslation('benefits', $locale, false);

        if (! is_array($value)) {
            return null;
        }

        /** @var list<string> $filtered */
        $filtered = array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));

        return $filtered === [] ? null : $filtered;
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
}
