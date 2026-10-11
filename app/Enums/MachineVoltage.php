<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Machine;

/**
 * Mains voltage a {@see Machine} unit is wired for.
 *
 * `Dual` covers units with an auto-switching PSU that accept either
 * mains. Kept as a single source of truth because the create and update
 * admin endpoints previously declared conflicting inline `in:` lists
 * (`220V,110V,Dual` vs `110v,220v,240v`).
 */
enum MachineVoltage: string
{
    case V110 = '110V';
    case V220 = '220V';
    case Dual = 'Dual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Resolve a client-supplied value case-insensitively so `220v` and
     * `220V` both land on the canonical casing.
     */
    public static function normalise(string $value): ?string
    {
        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case->value;
            }
        }

        return null;
    }
}
