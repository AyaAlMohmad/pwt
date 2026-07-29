<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Api\V1\Catalog\ProductDetailResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full admin shape for a {@see Product} row.
 *
 * Differs from the customer-facing
 * {@see ProductDetailResource}:
 *  - Returns raw bilingual JSON for `name`, `short_description`,
 *    `description`, `benefits` so the admin form can hydrate both
 *    locales in one fetch.
 *  - Exposes `is_active`, `is_machine_eligible`, `deleted_at`,
 *    `updated_at`, `orders_count`, `images_count`.
 *  - Includes ALL price rows (incl. inactive) via the `prices` relation,
 *    not just `activePrices`.
 *
 * Used for index list rows AND detail responses (single resource).
 *
 * @property-read Product $resource
 */
final class AdminProductDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->getTranslations('name'),
            'short_description' => $product->getTranslations('short_description'),
            'description' => $product->getTranslations('description'),
            'benefits' => $product->getTranslations('benefits'),
            'category_id' => (int) $product->category_id,
            'category' => $this->whenLoaded('category', function () use ($product, $request): ?array {
                $category = $product->category;
                if ($category === null) {
                    return null;
                }

                return (new AdminCategoryResource($category))->resolve($request);
            }),
            'is_active' => (bool) $product->is_active,
            'is_featured' => (bool) $product->is_featured,
            'is_quote_only' => (bool) $product->is_quote_only,
            'individual_buy_enabled' => (bool) $product->individual_buy_enabled,
            'discount_percentage' => $product->discount_percentage !== null ? (float) $product->discount_percentage : null,
            'original_price' => $product->original_price !== null ? (string) $product->original_price : null,
            'tag_label' => $product->tag_label,
            'rent_stars' => $product->rent_stars !== null ? (int) $product->rent_stars : null,
            'is_machine_eligible' => (bool) $product->is_machine_eligible,
            'stock_status' => $product->stock_status->value,
            'display_order' => (int) $product->display_order,
            'deleted_at' => $product->deleted_at?->toIso8601String(),
            'created_at' => $product->created_at->toIso8601String(),
            'updated_at' => $product->updated_at->toIso8601String(),
            'images' => AdminProductImageResource::collection($this->whenLoaded('images')),
            'specs' => AdminProductSpecResource::collection($this->whenLoaded('specs')),
            'prices' => AdminProductPriceResource::collection($this->whenLoaded('prices')),
            'orders_count' => $this->when(
                isset($product->orders_count),
                /** @phpstan-ignore-next-line property.notFound */
                fn (): int => (int) $product->orders_count,
            ),
            'images_count' => $this->whenCounted('images'),
        ];
    }
}
