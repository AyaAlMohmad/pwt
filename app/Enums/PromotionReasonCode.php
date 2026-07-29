<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable soft-fail reason for `POST /api/v1/promotions/validate`.
 *
 * The endpoint deliberately returns **200** with `valid:false` and one of
 * the values below in `reason_code` for all promo-rule failures (Stripe-
 * style preview). Hard failures (auth, validation, rate-limit, server)
 * use the standard error envelope and an HTTP 4xx/5xx status.
 *
 * See docs/architecture/promotions.md § 5.1 for the full mapping of each
 * `reason_code` to its trigger condition.
 *
 * `Applied` is reserved for the `valid:true` outcome and is never used as
 * a soft-fail reason in responses; it is provided so callers can compare
 * against a complete enumeration when constructing tests / fixtures.
 */
enum PromotionReasonCode: string
{
    case CodeUnknown = 'code_unknown';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case Inactive = 'inactive';
    case MinOrderNotMet = 'min_order_not_met';
    case ScopeMismatch = 'scope_mismatch';
    case PerUserLimitReached = 'per_user_limit_reached';
    case TotalLimitReached = 'total_limit_reached';
    case CurrencyMismatch = 'currency_mismatch';
    case Applied = 'applied';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Translation key for the customer-facing message.
     */
    public function messageKey(): string
    {
        return 'promotions.reason.'.$this->value;
    }
}
