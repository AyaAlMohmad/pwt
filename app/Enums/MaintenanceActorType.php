<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceStatusHistory;

/**
 * Who triggered a row in {@see MaintenanceStatusHistory}.
 *
 *  - `customer` — the request owner (e.g. submit, cancel-from-new).
 *  - `admin`    — an admin / operations user (Phase 18 transitions).
 *  - `system`   — automated transition (e.g. future SLA escalations).
 *
 * Per docs/architecture/machines-maintenance.md § 2.4 the customer-
 * facing response anonymises `actor_id` down to `{ first_name }` only.
 */
enum MaintenanceActorType: string
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
