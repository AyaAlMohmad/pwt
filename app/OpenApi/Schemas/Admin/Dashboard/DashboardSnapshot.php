<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Dashboard;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DashboardSnapshot',
    type: 'object',
    required: ['orders', 'revenue', 'maintenance', 'customers', 'machines', 'contact_leads'],
    properties: [
        new OA\Property(property: 'orders', ref: '#/components/schemas/DashboardOrdersBlock'),
        new OA\Property(property: 'revenue', ref: '#/components/schemas/DashboardRevenueBlock'),
        new OA\Property(property: 'maintenance', ref: '#/components/schemas/DashboardMaintenanceBlock'),
        new OA\Property(property: 'customers', ref: '#/components/schemas/DashboardCustomersBlock'),
        new OA\Property(property: 'machines', ref: '#/components/schemas/DashboardMachinesBlock'),
        new OA\Property(property: 'contact_leads', ref: '#/components/schemas/DashboardContactLeadsBlock'),
    ],
)]
final class DashboardSnapshot
{
    // Documentation-only.
}
