<?php

declare(strict_types=1);

namespace App\Exceptions\Support;

use App\Exceptions\AppException;

/**
 * Raised when an admin attempts a customer-visible action against a
 * terminal (closed / cancelled) support ticket.
 *
 * Internal notes (`is_internal=true`) bypass this guard because admins
 * may need to annotate audited tickets after the fact. See
 * docs/architecture/admin-support.md § 2.4 / § 5.5.
 *
 * Mapped by the global handler to HTTP 409 `ERR_TICKET_TERMINAL`.
 */
final class TicketTerminalException extends AppException
{
    public static function cannotReplyToTerminal(): self
    {
        return new self(
            __('admin.support.ticket_terminal'),
            409,
            'ERR_TICKET_TERMINAL',
        );
    }

    public static function resourceTrashed(): self
    {
        return new self(
            __('admin.support.trashed_modification'),
            409,
            'ERR_RESOURCE_TRASHED',
        );
    }
}
