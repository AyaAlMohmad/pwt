<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SupportTicketMessage;

/**
 * Author classification of a {@see SupportTicketMessage}.
 *
 * Phase 12 (customer-facing) only writes `customer` messages. Admin
 * Phase 19 writes `admin`; system-generated transition messages may
 * land later. See docs/architecture/support.md § 2.5.
 */
enum SupportTicketMessageSenderType: string
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
