<?php

declare(strict_types=1);

namespace App\Exceptions\Maintenance;

use App\Enums\MaintenanceStatus;
use App\Exceptions\AppException;

/**
 * Raised when a maintenance request status transition is attempted
 * outside the allowed state-machine map. Mapped to HTTP 409
 * `ERR_INVALID_STATUS_TRANSITION` by the global handler.
 *
 * See docs/architecture/machines-maintenance.md § 2.3 and § 5.6.
 */
final class InvalidMaintenanceTransitionException extends AppException
{
    public static function from(MaintenanceStatus $current, MaintenanceStatus $target): self
    {
        return new self(
            __('maintenance.invalid_status_transition', [
                'from' => $current->value,
                'to' => $target->value,
            ]),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Customer-friendly variants for the cancel surface — engineer-y
     * generic message is replaced by a context-specific one.
     */
    public static function cannotCancel(MaintenanceStatus $current): self
    {
        $key = match ($current) {
            MaintenanceStatus::Completed => 'maintenance.already_completed',
            MaintenanceStatus::Cancelled => 'maintenance.already_cancelled',
            default => 'maintenance.cancel_not_allowed',
        };

        return new self(
            __($key),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Phase 18 — PATCH /status body `status=assigned` is rejected.
     * Admins must use the dedicated PATCH /assign endpoint so the
     * `assigned_to_user_id` field is captured.
     */
    public static function useAssignEndpoint(): self
    {
        return new self(
            __('admin.maintenance.use_assign_endpoint'),
            409,
            'ERR_USE_ASSIGN_ENDPOINT',
        );
    }

    /**
     * Phase 18 — PATCH /status body `status=completed` is rejected.
     * Admins must use POST /close so `resolution_notes` is captured
     * and the machine maintenance log is created.
     */
    public static function useCloseEndpoint(): self
    {
        return new self(
            __('admin.maintenance.use_close_endpoint'),
            409,
            'ERR_USE_CLOSE_ENDPOINT',
        );
    }

    /**
     * Phase 18 — POST /close requires source state = in_progress.
     */
    public static function onlyInProgressCanClose(): self
    {
        return new self(
            __('admin.maintenance.only_in_progress_can_close'),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Phase 18 — PATCH /assign requires source state in {new, assigned}.
     */
    public static function cannotAssignInState(MaintenanceStatus $current): self
    {
        unset($current);

        return new self(
            __('admin.maintenance.cannot_assign_in_state'),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Customer reschedule attempted on a terminal-state request.
     */
    public static function cannotReschedule(MaintenanceStatus $current): self
    {
        $key = match ($current) {
            MaintenanceStatus::Completed => 'maintenance.already_completed',
            MaintenanceStatus::Cancelled => 'maintenance.already_cancelled',
            default => 'maintenance.reschedule_not_allowed',
        };

        return new self(
            __($key),
            409,
            'ERR_INVALID_STATUS_TRANSITION',
        );
    }

    /**
     * Phase 18 — write operation attempted on a soft-deleted machine or
     * maintenance request.
     */
    public static function resourceTrashed(string $key = 'admin.maintenance.resource_trashed'): self
    {
        return new self(
            __($key),
            409,
            'ERR_RESOURCE_TRASHED',
        );
    }
}
