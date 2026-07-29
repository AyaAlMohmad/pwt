<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\SupportTicket;

/**
 * Customer-selected category bucket for a {@see SupportTicket}.
 *
 * See docs/architecture/support.md § 2.3. Categories drive admin Phase
 * 19 routing / filtering. The customer self-selects from this fixed
 * list; no free-form input.
 */
enum SupportTicketCategory: string
{
    case OrderIssue = 'order_issue';
    case PaymentIssue = 'payment_issue';
    case MaintenanceIssue = 'maintenance_issue';
    case ProductQuestion = 'product_question';
    case AccountIssue = 'account_issue';
    case Feedback = 'feedback';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
