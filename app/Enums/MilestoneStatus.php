<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Visual state of a single milestone on the customer-facing
 * tracking timeline.
 */
enum MilestoneStatus: string
{
    case Completed = 'completed';
    case Current = 'current';
    case Pending = 'pending';

    /**
     * UI colour token sent to the frontend.
     */
    public function uiColour(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Current => 'blue',
            self::Pending => 'gray',
        };
    }
}
