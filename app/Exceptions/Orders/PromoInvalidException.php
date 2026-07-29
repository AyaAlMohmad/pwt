<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use App\Enums\PromotionReasonCode;
use App\Exceptions\AppException;
use App\Services\Promotions\PromotionEngine;

/**
 * Raised when a promo code attached to a new order fails
 * {@see PromotionEngine::validate()}.
 *
 * Mapped to HTTP 422 `ERR_PROMO_INVALID` with the engine's
 * `reason_code` echoed under `errors.promo_code[0]` so the customer
 * UI can render a precise message.
 *
 * See docs/architecture/orders.md § 3.1 / § 5.1.
 */
final class PromoInvalidException extends AppException
{
    public static function fromReason(PromotionReasonCode $reason, string $message): self
    {
        return new self(
            __('orders.promo_invalid'),
            422,
            'ERR_PROMO_INVALID',
            [
                'promo_code' => [$message],
                'reason_code' => [$reason->value],
            ],
        );
    }
}
