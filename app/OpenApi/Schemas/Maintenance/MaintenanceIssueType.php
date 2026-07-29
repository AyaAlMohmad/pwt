<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Maintenance;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MaintenanceIssueType',
    type: 'string',
    enum: [
        'filter_change', 'leak', 'noise', 'low_pressure',
        'water_quality', 'error_code', 'installation_fix', 'other',
    ],
    description: 'Customer-selectable issue category. Display labels translated server-side via lang/maintenance.php.',
)]
final class MaintenanceIssueType
{
    // Documentation-only.
}
