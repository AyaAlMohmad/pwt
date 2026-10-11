<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\MaintenanceRequest;
use App\Services\Maintenance\MaintenanceRequestService;

/**
 * Lifecycle status of a {@see MaintenanceRequest}.
 *
 * The state machine and allowed transitions are documented in
 * docs/architecture/machines-maintenance.md § 2. All transitions flow
 * through {@see MaintenanceRequestService::transitionTo()} so this map
 * is the single source of truth.
 */
enum MaintenanceStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Terminal states never transition further.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Map current status → permitted next statuses.
     *
     * See docs/architecture/machines-maintenance.md § 2.3.
     *
     * @return array<string, list<string>>
     */
    public static function transitionsAllowed(): array
    {
        return [
            self::New->value => [
                self::Assigned->value,
                // Technician assignment is optional — a request may
                // go straight to in_progress without an assignee.
                self::InProgress->value,
                self::Cancelled->value,
            ],
            self::Assigned->value => [
                self::InProgress->value,
                // Unassigning returns the request to `new`.
                self::New->value,
                self::Cancelled->value,
            ],
            self::InProgress->value => [
                self::Completed->value,
                self::Cancelled->value,
            ],
            self::Completed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::transitionsAllowed()[$this->value] ?? [], true);
    }

    /**
     * Numeric code accepted on the wire (e.g. admin status payloads).
     * Codes follow the enum declaration order.
     */
    public function code(): int
    {
        return match ($this) {
            self::New => 1,
            self::Assigned => 2,
            self::InProgress => 3,
            self::Completed => 4,
            self::Cancelled => 5,
        };
    }

    /**
     * Resolve a numeric status code to its case. Returns null for
     * unknown codes so callers can surface a validation error.
     */
    public static function tryFromCode(int $code): ?self
    {
        return match ($code) {
            1 => self::New,
            2 => self::Assigned,
            3 => self::InProgress,
            4 => self::Completed,
            5 => self::Cancelled,
            default => null,
        };
    }
}
