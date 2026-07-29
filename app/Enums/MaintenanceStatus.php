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
                self::Cancelled->value,
            ],
            self::Assigned->value => [
                self::InProgress->value,
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
}
