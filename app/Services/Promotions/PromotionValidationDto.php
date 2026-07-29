<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Enums\PromotionReasonCode;
use App\Http\Resources\Api\V1\Promotions\PromotionValidationResource;

/**
 * Immutable result of {@see PromotionEngine::validate()}.
 *
 * The DTO is what the `/promotions/validate` endpoint serialises through
 * {@see PromotionValidationResource}.
 * It is also what Phase 8's `OrderService::createOrder()` consumes inside
 * the order-creation transaction — the engine is the single source of
 * truth for promo evaluation (docs/architecture/promotions.md § 1.5).
 *
 * For soft-fail outcomes (`valid=false`) the discount-related fields are
 * null and `reasonCode` describes the failure. For the success outcome
 * (`valid=true`) `reasonCode` is `Applied` and the discount fields are
 * populated.
 */
final readonly class PromotionValidationDto
{
    /**
     * @param  string  $message  Translated, customer-facing message.
     */
    public function __construct(
        public bool $valid,
        public string $code,
        public ?PromotionReasonCode $reasonCode,
        public string $message,
        public ?int $promotionId = null,
        public ?DiscountType $discountType = null,
        public ?float $discountValue = null,
        public ?string $discountCurrency = null,
        public ?PromoScope $appliesTo = null,
        public ?float $appliedAmount = null,
        public ?string $currency = null,
    ) {}

    /**
     * Build a soft-fail DTO. Convenience factory used heavily by the
     * engine — keeps the validation methods compact.
     */
    public static function fail(
        string $code,
        PromotionReasonCode $reason,
        string $message,
        ?int $promotionId = null,
    ): self {
        return new self(
            valid: false,
            code: $code,
            reasonCode: $reason,
            message: $message,
            promotionId: $promotionId,
        );
    }

    /**
     * Build a success DTO.
     */
    public static function success(
        string $code,
        int $promotionId,
        DiscountType $discountType,
        float $discountValue,
        ?string $discountCurrency,
        PromoScope $appliesTo,
        float $appliedAmount,
        string $currency,
        string $message,
    ): self {
        return new self(
            valid: true,
            code: $code,
            reasonCode: PromotionReasonCode::Applied,
            message: $message,
            promotionId: $promotionId,
            discountType: $discountType,
            discountValue: $discountValue,
            discountCurrency: $discountCurrency,
            appliesTo: $appliesTo,
            appliedAmount: $appliedAmount,
            currency: $currency,
        );
    }
}
