<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Promotions;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing shape of a {@see Promotion} row.
 *
 * Sensitive admin-only fields are **masked**:
 *   - `total_limit`     — never exposed.
 *   - `per_user_limit`  — never exposed; only the remaining-count is
 *                         derived (and only when authenticated).
 *
 * `user_redemptions_remaining` is computed lazily by counting the
 * `promotion_redemptions` rows for the current user. The value is null
 * when the request is unauthenticated OR when `per_user_limit` is null
 * (unlimited).
 *
 * @property-read Promotion $resource
 */
final class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $promotion->id,
            'code' => $promotion->code,
            'name' => $promotion->getTranslation('name', $locale),
            'description' => $promotion->getTranslation('description', $locale, false) ?: null,
            'type' => $promotion->type->value,
            'value' => $this->castMoney($promotion->value),
            'max_discount_amount' => $promotion->max_discount_amount !== null
                ? $this->castMoney($promotion->max_discount_amount)
                : null,
            'currency' => $promotion->currency,
            'min_order_amount' => $promotion->min_order_amount !== null
                ? $this->castMoney($promotion->min_order_amount)
                : null,
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'applies_to' => $promotion->applies_to->value,
            'first_applicable_name' => $this->resolveFirstApplicableName($promotion),
            'remaining_applicable_count' => $this->resolveRemainingApplicableCount($promotion),
            'user_redemptions_remaining' => $this->resolveUserRedemptionsRemaining($promotion, $request),
        ];
    }

    /**
     * First applicable product or category name for scoped promotions.
     */
    private function resolveFirstApplicableName(Promotion $promotion): ?string
    {
        $locale = app()->getLocale();

        return match ($promotion->applies_to->value) {
            'products' => $this->firstProductName($promotion, $locale),
            'categories' => $this->firstCategoryName($promotion, $locale),
            default => null,
        };
    }

    /**
     * Count of remaining applicable products or categories after the first.
     */
    private function resolveRemainingApplicableCount(Promotion $promotion): ?int
    {
        return match ($promotion->applies_to->value) {
            'products' => $this->remainingCount($promotion->products),
            'categories' => $this->remainingCount($promotion->categories),
            default => null,
        };
    }

    private function firstProductName(Promotion $promotion, string $locale): ?string
    {
        if (! $promotion->relationLoaded('products')) {
            return null;
        }

        $first = $promotion->products->first();

        return $first !== null
            ? ($first->getTranslation('name', $locale, false)
                ?: $first->getTranslation('name', config('app.fallback_locale', 'en')))
            : null;
    }

    private function firstCategoryName(Promotion $promotion, string $locale): ?string
    {
        if (! $promotion->relationLoaded('categories')) {
            return null;
        }

        $first = $promotion->categories->first();

        return $first !== null
            ? ($first->getTranslation('name', $locale, false)
                ?: $first->getTranslation('name', config('app.fallback_locale', 'en')))
            : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $collection
     */
    private function remainingCount($collection): ?int
    {
        $count = $collection->count();

        return $count > 0 ? max(0, $count - 1) : null;
    }

    /**
     * Compute the per-user redemptions remaining when the request is
     * authenticated AND the promotion has a `per_user_limit`. Returns
     * null otherwise (unlimited or anonymous request).
     */
    private function resolveUserRedemptionsRemaining(Promotion $promotion, Request $request): ?int
    {
        if ($promotion->per_user_limit === null) {
            return null;
        }

        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $used = $promotion->redemptions()
            ->where('user_id', $user->getAuthIdentifier())
            ->count();

        $remaining = (int) $promotion->per_user_limit - $used;

        return max(0, $remaining);
    }

    /**
     * Normalise a `decimal:2` cast string into a float for JSON output.
     * Storing as float keeps wire size small and avoids client-side
     * parseFloat boilerplate; precision is bounded by the schema.
     */
    private function castMoney(string $value): float
    {
        return (float) $value;
    }
}
