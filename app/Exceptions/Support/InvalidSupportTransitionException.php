<?php

declare(strict_types=1);

namespace App\Exceptions\Support;

use App\Enums\SupportTicketStatus;
use App\Exceptions\AppException;

/**
 * Raised when a support ticket status transition is attempted outside
 * the allowed state-machine map. Mapped to HTTP 409
 * `ERR_INVALID_STATUS_TRANSITION` by the global handler.
 *
 * See docs/architecture/support.md § 2.6 and § 10.4.
 */
final class InvalidSupportTransitionException extends AppException
{
    public static function from(SupportTicketStatus $current, SupportTicketStatus $target): self
    {
        return new self(
            __('support.invalid_status_transition', [
                'from' => $current->value,
                'to' => $target->value,
            ]),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Customer-friendly variants for the cancel surface — the engineer-y
     * generic message is replaced by a context-specific one.
     */
    public static function cannotCancel(SupportTicketStatus $current): self
    {
        $key = match ($current) {
            SupportTicketStatus::Cancelled => 'support.already_cancelled',
            SupportTicketStatus::Closed => 'support.already_closed',
            SupportTicketStatus::Resolved => 'support.already_resolved',
            default => 'support.cancel_not_allowed',
        };

        return new self(
            __($key),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }
}
