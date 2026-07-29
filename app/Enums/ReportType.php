<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\ReportPolicy;
use App\Services\Reports\ReportBuilder;

/**
 * The four admin report flavours exposed by Phase 21.
 *
 * Backed string enum used as the typed input to
 * {@see ReportBuilder::build()} and the exporter
 * dispatch. The {@see ReportPolicy} matches on `value`
 * (string) so `support` role can be restricted to maintenance + customers
 * without needing a separate permission key per type.
 *
 * See docs/architecture/admin-reports.md § 8.2.
 */
enum ReportType: string
{
    case Sales = 'sales';
    case Orders = 'orders';
    case Maintenance = 'maintenance';
    case Customers = 'customers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
