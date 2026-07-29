<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Notification;

/**
 * Internal delivery state machine for a {@see Notification}.
 *
 * Transitions:
 *   queued → sent → delivered  (in-app reaches delivered synchronously)
 *   queued → failed            (job exceeded retries or channel returned false)
 *   sent   → failed            (driver soft-fail post-hand-off)
 *
 * `delivered` and `failed` are terminal. The customer-facing inbox
 * never exposes this column — it is admin / observability only.
 * See docs/architecture/notifications.md § 2.1.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed], true);
    }
}
