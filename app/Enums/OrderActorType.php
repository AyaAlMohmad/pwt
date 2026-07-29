<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who triggered a row in `order_status_history`.
 *
 * `customer` — the order owner (e.g. cancel from mobile app).
 * `admin`    — an admin / operations user (Phase 17).
 * `system`   — automated transition (payment webhook, cron auto-complete).
 *
 * Per docs/architecture/orders.md § 4.4 the `actor_id` is hidden from
 * customer responses; only the `actor_type` is exposed.
 */
enum OrderActorType: string
{
    case Customer = 'customer';
    case Admin = 'admin';
    case System = 'system';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
