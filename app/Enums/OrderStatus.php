<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Order;
use App\Services\Orders\OrderStatusTransitionService;

/**
 * Lifecycle status of an {@see Order}.
 *
 * The full state machine (allowed transitions, actors per transition,
 * side-effects) is documented in docs/architecture/orders.md § 2.
 *
 * `pending` is the only state in which a customer can place an order;
 * `cancelled` and `completed` are terminal. All status mutations flow
 * through {@see OrderStatusTransitionService} so
 * the transitionsAllowed() map is the single source of truth.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Scheduled = 'scheduled';
    case OutForDelivery = 'out_for_delivery';
    case Installed = 'installed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Terminal statuses cannot transition further.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Customer-side cancellable window: only `pending` and `confirmed`.
     * Admin-side cancellation rules are checked in the service layer.
     */
    public function isCustomerCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }

    /**
     * Map current status → permitted next statuses (full state machine).
     *
     * See docs/architecture/orders.md § 2.3.
     *
     * @return array<string, list<string>>
     */
    public static function transitionsAllowed(): array
    {
        return [
            self::Pending->value => [
                self::Confirmed->value,
                self::Cancelled->value,
            ],
            self::Confirmed->value => [
                self::Scheduled->value,
                self::Cancelled->value,
            ],
            self::Scheduled->value => [
                self::OutForDelivery->value,
                self::Cancelled->value,
            ],
            self::OutForDelivery->value => [
                self::Installed->value,
                self::Cancelled->value,
            ],
            self::Installed->value => [
                self::Completed->value,
                self::Cancelled->value,
            ],
            self::Completed->value => [],
            self::Cancelled->value => [],
        ];
    }

    /**
     * Returns true if a transition from $this → $target is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::transitionsAllowed()[$this->value] ?? [], true);
    }

    /**
     * Customer-facing progress mapping (0..100). Stable across phases —
     * mobile app progress bar code never breaks.
     */
    public function trackingProgress(): int
    {
        return match ($this) {
            self::Pending => 10,
            self::Confirmed => 25,
            self::Scheduled => 50,
            self::OutForDelivery => 70,
            self::Installed => 90,
            self::Completed => 100,
            self::Cancelled => 0,
        };
    }
}
