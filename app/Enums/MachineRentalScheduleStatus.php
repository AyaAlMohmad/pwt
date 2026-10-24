<?php

declare(strict_types=1);

namespace App\Enums;

enum MachineRentalScheduleStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
