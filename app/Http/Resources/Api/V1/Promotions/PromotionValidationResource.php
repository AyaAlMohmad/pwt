<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Promotions;

use App\Services\Promotions\PromotionEngine;
use App\Services\Promotions\PromotionValidationDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the {@see PromotionValidationDto} returned by
 * {@see PromotionEngine::validate()}.
 *
 * The resource shape is documented in
 * docs/architecture/promotions.md § 4.3. The same envelope is returned
 * for both `valid=true` and `valid=false` outcomes (Stripe-style soft-
 * fail; § 5.1). HTTP status is always 200 for both cases.
 *
 * @property-read PromotionValidationDto $resource
 */
final class PromotionValidationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PromotionValidationDto $dto */
        $dto = $this->resource;

        return [
            'valid' => $dto->valid,
            'code' => $dto->code,
            'reason_code' => $dto->reasonCode?->value,
            'message' => $dto->message,
            'discount' => $dto->valid && $dto->discountType !== null && $dto->discountValue !== null
                ? [
                    'type' => $dto->discountType->value,
                    'value' => $dto->discountValue,
                    'currency' => $dto->discountCurrency,
                    'applies_to' => $dto->appliesTo?->value,
                ]
                : null,
            'applied_amount' => $dto->appliedAmount,
            'currency' => $dto->currency,
            'promotion_id' => $dto->promotionId,
        ];
    }
}
