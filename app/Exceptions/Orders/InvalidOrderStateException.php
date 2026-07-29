<?php

declare(strict_types=1);

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\AppException;

/**
 * Raised when an admin operation is attempted against an order whose
 * current state forbids it — distinct from
 * {@see InvalidStatusTransitionException} which fires inside the state
 * machine for transition violations.
 *
 * Two concrete uses in Phase 17:
 *  - The notes endpoint refuses to write rows on terminal orders
 *    (`completed` / `cancelled`) — `ERR_INVALID_ORDER_STATE`.
 *  - The generic PATCH /status endpoint refuses `status=scheduled`
 *    because that path doesn't capture `scheduled_for_at` /
 *    `technician_user_id` — `ERR_USE_SCHEDULE_ENDPOINT`.
 *
 * Both map to HTTP 409 by the global handler.
 *
 * See docs/architecture/admin-orders.md § 5.3 / § 5.6.
 */
final class InvalidOrderStateException extends AppException
{
    public static function notesOnTerminal(OrderStatus $current): self
    {
        return new self(
            __('admin.orders.notes_on_terminal', ['status' => $current->value]),
            409,
            'ERR_INVALID_ORDER_STATE',
        );
    }

    public static function useScheduleEndpoint(): self
    {
        return new self(
            __('admin.orders.use_schedule_endpoint'),
            409,
            'ERR_USE_SCHEDULE_ENDPOINT',
        );
    }

    public static function scheduleRequiresConfirmed(OrderStatus $current): self
    {
        return new self(
            __('admin.orders.schedule_requires_confirmed', ['status' => $current->value]),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }
}
