<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Priority level of a maintenance request.
 *
 * Customers are capped at `medium` server-side; admins can elevate to
 * `high` / `urgent` via Phase 18 endpoints.
 */
enum MaintenancePriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * The maximum priority a customer-supplied value may take. Higher
     * values are coerced down inside the Form Request `prepareForValidation`.
     */
    public static function customerCap(): self
    {
        return self::Medium;
    }
}
