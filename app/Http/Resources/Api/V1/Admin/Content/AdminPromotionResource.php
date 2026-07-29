<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list shape for a {@see Promotion} row.
 *
 * Admins see raw bilingual JSON for translatable fields plus the
 * admin-only configuration columns (`total_limit`, `per_user_limit`,
 * `max_discount_amount`, etc.) — docs/architecture/admin-content.md § 4.2.
 *
 * `redemptions_count` is populated when the controller eager-loaded the
 * `withCount('redemptions')` aggregate.
 *
 * @property-read Promotion $resource
 */
class AdminPromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->resource;

        $productInfo = $this->resolveProductInfo($promotion);

        return [
            'id' => $promotion->id,
            'code' => $promotion->code,
            'name' => $promotion->getTranslations('name'),
            'description' => $promotion->getTranslations('description'),
            'type' => $promotion->type->value,
            'value' => (string) $promotion->value,
            'max_discount_amount' => $promotion->max_discount_amount !== null
                ? (string) $promotion->max_discount_amount
                : null,
            'currency' => $promotion->currency,
            'min_order_amount' => $promotion->min_order_amount !== null
                ? (string) $promotion->min_order_amount
                : null,
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'per_user_limit' => $promotion->per_user_limit,
            'total_limit' => $promotion->total_limit,
            'applies_to' => $promotion->applies_to->value,
            'is_active' => (bool) $promotion->is_active,
            'status' => $this->computeStatus($promotion),
            'redemptions_count' => $this->whenCounted('redemptions'),
            'first_product' => $productInfo['first_product'],
            'product_count' => $productInfo['product_count'],
            'deleted_at' => $promotion->deleted_at?->toIso8601String(),
            'created_at' => $promotion->created_at->toIso8601String(),
            'updated_at' => $promotion->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array{first_product: array<string, mixed>|null, product_count: int|null}
     */
    protected function resolveProductInfo(Promotion $promotion): array
    {
        if (! $promotion->relationLoaded('products')) {
            return ['first_product' => null, 'product_count' => null];
        }

        $products = $promotion->products;
        $count = $products->count();

        if ($count === 0) {
            return ['first_product' => null, 'product_count' => 0];
        }

        $first = $products->first();
        $locale = app()->getLocale();

        return [
            'first_product' => [
                'id' => $first->id,
                'code' => $first->code,
                'name' => $first->getTranslation('name', $locale, false)
                    ?: $first->getTranslation('name', (string) config('app.fallback_locale', 'en')),
            ],
            'product_count' => $count,
        ];
    }

    /**
     * Synthetic status: trashed | inactive | upcoming | expired | active.
     */
    protected function computeStatus(Promotion $promotion): string
    {
        if ($promotion->trashed()) {
            return 'trashed';
        }
        if (! $promotion->is_active) {
            return 'inactive';
        }
        $now = now();
        if ($promotion->starts_at !== null && $promotion->starts_at->isAfter($now)) {
            return 'upcoming';
        }
        if ($promotion->ends_at !== null && $promotion->ends_at->isBefore($now)) {
            return 'expired';
        }

        return 'active';
    }
}
