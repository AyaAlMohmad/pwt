<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type / category of a contact-form submission.
 *
 * Stored as a string column on `contact_leads.type`.
 */
enum ContactLeadType: string
{
    case General = 'general';
    case Business = 'business';

    public static function default(): self
    {
        return self::General;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
