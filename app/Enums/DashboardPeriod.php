<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Time-window filter for the admin dashboard snapshot.
 *
 * Controls the "current" vs "previous" comparison anchors
 * for trend metrics (orders, revenue, customers, machines).
 * Absolute state metrics (e.g. total active machines,
 * pending maintenance) are NOT affected by the period.
 */
enum DashboardPeriod: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last_7_days';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';

    public static function default(): self
    {
        return self::Today;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
