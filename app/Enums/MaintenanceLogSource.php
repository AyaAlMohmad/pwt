<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MachineMaintenanceLog;

/**
 * Where a {@see MachineMaintenanceLog} row originated.
 *
 *  - `imported` — written by `MachinesFromExcelSeeder` from the 227-row
 *                 production seed.
 *  - `manual`   — admin-created via Phase 18 endpoints.
 *  - `request`  — auto-created when a `MaintenanceRequest` is completed.
 */
enum MaintenanceLogSource: string
{
    case Imported = 'imported';
    case Manual = 'manual';
    case Request = 'request';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
