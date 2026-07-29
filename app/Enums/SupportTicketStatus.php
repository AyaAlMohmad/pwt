<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;

/**
 * Lifecycle status of a {@see SupportTicket}.
 *
 * State machine and allowed transitions are documented in
 * docs/architecture/support.md § 2. All Phase 12 transitions flow through
 * {@see SupportTicketService} so this map is the single source of truth.
 *
 * Phase 12 (customer-facing) exposes only:
 *   - `(none) -> open` via `submit()`
 *   - `open -> cancelled` via `cancel()`
 *
 * Admin-initiated transitions (assign / progress / resolve / close /
 * admin-cancel from broader states) land in Phase 19.
 */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Terminal states never transition further.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /**
     * True when the customer is allowed to cancel from this state.
     * Customer-side cancel is restricted to `open` only — beyond that
     * admins have taken ownership and only the admin path may cancel.
     */
    public function isCustomerCancellable(): bool
    {
        return $this === self::Open;
    }

    /**
     * Map current status -> permitted next statuses (admin + customer
     * combined; the customer-side path is filtered further by
     * `isCustomerCancellable()`).
     *
     * See docs/architecture/support.md § 2.6.
     *
     * @return array<string, list<string>>
     */
    public static function transitionsAllowed(): array
    {
        return [
            self::Open->value => [
                self::Assigned->value,
                self::Cancelled->value,
            ],
            self::Assigned->value => [
                self::InProgress->value,
                self::Cancelled->value,
            ],
            self::InProgress->value => [
                self::Resolved->value,
                self::Cancelled->value,
            ],
            self::Resolved->value => [
                self::Closed->value,
                self::Cancelled->value,
            ],
            self::Closed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::transitionsAllowed()[$this->value] ?? [], true);
    }
}
