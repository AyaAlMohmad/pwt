<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Machine;

/**
 * Where a {@see Machine} row originated.
 *
 *  - `imported` — created by `MachinesFromExcelSeeder` (the 227 real units).
 *  - `created`  — created at runtime by the order→install listener.
 */
enum MachineSource: string
{
    case Imported = 'imported';
    case Created = 'created';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
