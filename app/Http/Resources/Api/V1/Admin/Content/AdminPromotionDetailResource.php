<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Enums\PromoScope;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Admin\AdminPromotionService;
use Illuminate\Http\Request;

/**
 * Admin DETAIL shape for a {@see Promotion}.
 *
 * Adds (vs list shape):
 *  - `products` (when applies_to=products): `[{ id, code, name }, ...]`
 *  - `categories` (when applies_to=categories): `[{ id, code, name }, ...]`
 *  - `total_amount_redeemed` — SUM(amount_applied)
 *  - `unique_users_count` — DISTINCT user_id
 *
 * The two aggregates and the scoped pivots are computed in
 * {@see AdminPromotionService::find()}.
 *
 * @property-read Promotion $resource
 */
final class AdminPromotionDetailResource extends AdminPromotionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->resource;
        $base = parent::toArray($request);

        $base['products'] = null;
        $base['categories'] = null;
        if ($promotion->applies_to === PromoScope::Products && $promotion->relationLoaded('products')) {
            $base['products'] = $promotion->products
                ->map(static fn (Product $p): array => [
                    'id' => (int) $p->id,
                    'code' => $p->code,
                    'name' => $p->getTranslations('name'),
                ])
                ->values()
                ->all();
        }
        if ($promotion->applies_to === PromoScope::Categories && $promotion->relationLoaded('categories')) {
            $base['categories'] = $promotion->categories
                ->map(static fn (Category $c): array => [
                    'id' => (int) $c->id,
                    'code' => $c->code,
                    'name' => $c->getTranslations('name'),
                ])
                ->values()
                ->all();
        }

        $base['total_amount_redeemed'] = $promotion->getAttribute('total_amount_redeemed')
            ?? '0.00';
        $base['unique_users_count'] = (int) ($promotion->getAttribute('unique_users_count') ?? 0);

        return $base;
    }
}
