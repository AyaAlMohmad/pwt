<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MachineMaintenanceLog;

/**
 * Status of an individual {@see MachineMaintenanceLog} row.
 *
 * Imported rows from the Excel seed are always `completed`. Future
 * Phase 18 admin endpoints can create `scheduled` rows ahead of time and
 * `missed`/`cancelled` rows as part of the operational workflow.
 */
enum MaintenanceLogStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
