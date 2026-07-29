<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of an installation appointment associated with an order.
 *
 * Phase 8 only writes `scheduled` and `cancelled` rows (via the order
 * status machine); `in_progress` / `completed` transitions land in
 * Phase 17 (admin / technician app). See docs/architecture/orders.md
 * § 8.4.
 */
enum InstallationStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
