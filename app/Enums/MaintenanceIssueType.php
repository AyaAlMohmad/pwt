<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Customer-selectable issue category on a maintenance request.
 *
 * Display labels are translated in `lang/{en,ar}/maintenance.php`
 * under the `issue_type.<value>` key.
 */
enum MaintenanceIssueType: string
{
    case FilterChange = 'filter_change';
    case Leak = 'leak';
    case Noise = 'noise';
    case LowPressure = 'low_pressure';
    case WaterQuality = 'water_quality';
    case ErrorCode = 'error_code';
    case InstallationFix = 'installation_fix';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
