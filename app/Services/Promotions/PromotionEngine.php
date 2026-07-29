<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Enums\PromotionReasonCode;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for promotion evaluation.
 *
 * Shared by:
 *   - Phase 7 `POST /api/v1/promotions/validate` (this module).
 *   - Phase 6 retroactive `ProductDetailResource::promotions_count`
 *     (via {@see countActiveForProduct()}).
 *   - Phase 8 `OrderService::createOrder()` (will call
 *     {@see validate()} inside the order-creation transaction).
 *
 * The engine is **read-only** — it never writes to the database. The
 * `over_total_limit` / `over_user_limit` checks query
 * `promotion_redemptions` but do not lock or reserve rows; Phase 8
 * closes the race window with a `SELECT ... FOR UPDATE` on the
 * `promotions` row inside the transaction.
 *
 * Currency rules (docs/architecture/promotions.md § 9.8):
 *   - `percent` promos are currency-agnostic. `min_order_amount` is
 *     interpreted in the request's `currency`.
 *   - `fixed` promos require the request's `currency` to equal the
 *     promotion's `currency`; mismatch → `currency_mismatch`.
 */
final class PromotionEngine
{
    /**
     * Currently-active promotions, ordered for the customer-facing
     * `GET /api/v1/promotions` listing.
     *
     * @return Collection<int, Promotion>
     */
    public function listActive(): Collection
    {
        return Promotion::query()
            ->active()
            ->with(['products', 'categories'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Look up an active promotion by its code (case-insensitive). Returns
     * null when the code does not exist, is inactive, soft-deleted, or
     * outside its active window. Stored codes are uppercase; the lookup
     * normalises the input.
     */
    public function findActiveByCode(string $code): ?Promotion
    {
        $normalised = strtoupper(trim($code));
        if ($normalised === '') {
            return null;
        }

        /** @var Promotion|null $promotion */
        $promotion = Promotion::query()
            ->active()
            ->where('code', $normalised)
            ->first();

        return $promotion;
    }

    /**
     * Count of currently-active promotions that apply to a given
     * product. Used by Phase 6 retroactive wire on
     * `ProductDetailResource::promotions_count`.
     *
     * Counts a promotion when:
     *   - `applies_to = all`, OR
     *   - `applies_to = products` AND a `promotion_products` row matches
     *     the product, OR
     *   - `applies_to = categories` AND a `promotion_categories` row
     *     matches the product's category (only when `$categoryId` is
     *     supplied).
     */
    public function countActiveForProduct(int $productId, ?int $categoryId): int
    {
        $query = Promotion::query()->active();

        $query->where(static function ($q) use ($productId, $categoryId): void {
            $q->where('applies_to', PromoScope::All->value)
                ->orWhere(static function ($qp) use ($productId): void {
                    $qp->where('applies_to', PromoScope::Products->value)
                        ->whereExists(static function ($sub) use ($productId): void {
                            $sub->select(DB::raw(1))
                                ->from('promotion_products')
                                ->whereColumn('promotion_products.promotion_id', 'promotions.id')
                                ->where('promotion_products.product_id', $productId);
                        });
                });

            if ($categoryId !== null) {
                $q->orWhere(static function ($qc) use ($categoryId): void {
                    $qc->where('applies_to', PromoScope::Categories->value)
                        ->whereExists(static function ($sub) use ($categoryId): void {
                            $sub->select(DB::raw(1))
                                ->from('promotion_categories')
                                ->whereColumn('promotion_categories.promotion_id', 'promotions.id')
                                ->where('promotion_categories.category_id', $categoryId);
                        });
                });
            }
        });

        return $query->count();
    }

    /**
     * Validate a promo code against an order context.
     *
     * Returns a {@see PromotionValidationDto}. Never throws for promo-
     * rule failures (Stripe-style soft-fail). Only throws for genuine
     * infrastructure errors (DB unavailable, etc.) which propagate up
     * through Laravel's exception handler.
     *
     * @param  list<int>  $productIds  Optional cart product ids (used by `applies_to=products`).
     * @param  list<int>  $categoryIds  Optional cart category ids (used by `applies_to=categories`).
     */
    public function validate(
        string $code,
        User $user,
        float $subtotal,
        string $currency,
        array $productIds = [],
        array $categoryIds = [],
    ): PromotionValidationDto {
        $normalisedCode = strtoupper(trim($code));
        $currency = strtoupper($currency);

        // Single lookup (without the active-window scope) so we can
        // distinguish between "no such code" and "inactive/expired".
        /** @var Promotion|null $promotion */
        $promotion = Promotion::query()
            ->where('code', $normalisedCode)
            ->first();

        if ($promotion === null) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::CodeUnknown,
                __('promotions.reason.code_unknown'),
            );
        }

        // Inactive / not-started / expired window checks.
        if (! $promotion->is_active) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::Inactive,
                __('promotions.reason.inactive'),
                $promotion->id,
            );
        }

        $now = now();
        if ($promotion->starts_at !== null && $promotion->starts_at->isAfter($now)) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::NotStarted,
                __('promotions.reason.not_started'),
                $promotion->id,
            );
        }
        if ($promotion->ends_at !== null && $promotion->ends_at->isBefore($now)) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::Expired,
                __('promotions.reason.expired'),
                $promotion->id,
            );
        }

        // Currency match — fixed promos only.
        if ($promotion->type === DiscountType::Fixed
            && $promotion->currency !== null
            && $promotion->currency !== $currency
        ) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::CurrencyMismatch,
                __('promotions.reason.currency_mismatch', ['currency' => $currency]),
                $promotion->id,
            );
        }

        // Minimum order amount. Interpreted in the request's currency
        // (per § 9.8 — flagged limitation).
        if ($promotion->min_order_amount !== null
            && $subtotal < (float) $promotion->min_order_amount
        ) {
            $missing = (float) $promotion->min_order_amount - $subtotal;

            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::MinOrderNotMet,
                __('promotions.reason.min_order_not_met', [
                    'amount' => $this->formatMoney($missing, $currency),
                ]),
                $promotion->id,
            );
        }

        // Scope match.
        if (! $this->scopeMatches($promotion, $productIds, $categoryIds)) {
            return PromotionValidationDto::fail(
                $normalisedCode,
                PromotionReasonCode::ScopeMismatch,
                __('promotions.reason.scope_mismatch'),
                $promotion->id,
            );
        }

        // Total / per-user limits — read-only checks.
        if ($promotion->total_limit !== null) {
            $totalUsed = $promotion->redemptions()->count();
            if ($totalUsed >= $promotion->total_limit) {
                return PromotionValidationDto::fail(
                    $normalisedCode,
                    PromotionReasonCode::TotalLimitReached,
                    __('promotions.reason.total_limit_reached'),
                    $promotion->id,
                );
            }
        }

        if ($promotion->per_user_limit !== null) {
            $userUsed = $promotion->redemptions()
                ->where('user_id', $user->getAuthIdentifier())
                ->count();

            if ($userUsed >= $promotion->per_user_limit) {
                return PromotionValidationDto::fail(
                    $normalisedCode,
                    PromotionReasonCode::PerUserLimitReached,
                    __('promotions.reason.per_user_limit_reached'),
                    $promotion->id,
                );
            }
        }

        // All checks passed — compute the applied amount.
        $applied = $this->computeAppliedAmount($promotion, $subtotal);

        return PromotionValidationDto::success(
            code: $normalisedCode,
            promotionId: $promotion->id,
            discountType: $promotion->type,
            discountValue: (float) $promotion->value,
            discountCurrency: $promotion->currency,
            appliesTo: $promotion->applies_to,
            appliedAmount: $applied,
            currency: $currency,
            message: __('promotions.reason.applied', [
                'amount' => $this->formatMoney($applied, $currency),
            ]),
        );
    }

    /**
     * @param  list<int>  $productIds
     * @param  list<int>  $categoryIds
     */
    private function scopeMatches(Promotion $promotion, array $productIds, array $categoryIds): bool
    {
        return match ($promotion->applies_to) {
            PromoScope::All => true,
            PromoScope::Products => $productIds !== [] && DB::table('promotion_products')
                ->where('promotion_id', $promotion->id)
                ->whereIn('product_id', $productIds)
                ->exists(),
            PromoScope::Categories => $categoryIds !== [] && DB::table('promotion_categories')
                ->where('promotion_id', $promotion->id)
                ->whereIn('category_id', $categoryIds)
                ->exists(),
        };
    }

    /**
     * Compute the applied discount amount, honouring the max-discount
     * cap for percent promos. Rounded to 2 decimals (bankers' rounding
     * is PHP's default for `round()` is no — `round()` defaults to
     * half-away-from-zero. We use that to stay consistent with money
     * displayed elsewhere in the app).
     */
    private function computeAppliedAmount(Promotion $promotion, float $subtotal): float
    {
        $value = (float) $promotion->value;

        $raw = match ($promotion->type) {
            DiscountType::Percent => $subtotal * ($value / 100.0),
            DiscountType::Fixed => $value,
        };

        // Cap percent promos at `max_discount_amount` when set.
        if ($promotion->type === DiscountType::Percent
            && $promotion->max_discount_amount !== null
        ) {
            $cap = (float) $promotion->max_discount_amount;
            if ($raw > $cap) {
                $raw = $cap;
            }
        }

        // Cap any discount at the subtotal so the customer never owes
        // negative money. Defensive — should not trigger in practice.
        if ($raw > $subtotal) {
            $raw = $subtotal;
        }

        return round($raw, 2);
    }

    /**
     * Format a money amount for inline display in a translated string
     * (e.g. "Save AED 75.00"). Keeps the formatting consistent across
     * all locales — the currency code prefixes the amount.
     */
    private function formatMoney(float $amount, string $currency): string
    {
        return sprintf('%s %.2f', strtoupper($currency), $amount);
    }
}
