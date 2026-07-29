<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SupportTicket;

/**
 * Internal priority level of a {@see SupportTicket}.
 *
 * Customer submissions ALWAYS start at `medium` — the customer never
 * sets priority. Admins elevate via Phase 19 endpoints. See
 * docs/architecture/support.md § 2.4.
 */
enum SupportTicketPriority: string
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
}
