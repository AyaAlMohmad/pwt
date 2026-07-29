<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Source channel that produced a contact-form submission.
 *
 * Stored as a string column on `contact_leads.source`. See
 * docs/architecture/contact.md § 8.1.
 */
enum ContactLeadSource: string
{
    case Web = 'web';
    case Mobile = 'mobile';
    case Other = 'other';

    public static function default(): self
    {
        return self::Web;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
