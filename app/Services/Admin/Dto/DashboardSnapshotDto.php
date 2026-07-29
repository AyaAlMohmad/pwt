<?php

declare(strict_types=1);

namespace App\Services\Admin\Dto;

use App\Services\Admin\DashboardService;
use Illuminate\Support\Carbon;

/**
 * Immutable DTO returned by
 * {@see DashboardService::snapshot()}.
 *
 * Keeps the dashboard's response shape stable across the cache boundary
 * (cached + fresh both flow through the same DTO). See
 * docs/architecture/admin-audit-templates-dashboard.md § 4.4.
 */
final class DashboardSnapshotDto
{
    /**
     * @param  array<string, mixed>  $orders
     * @param  array<string, mixed>  $revenue
     * @param  array<string, mixed>  $maintenance
     * @param  array<string, mixed>  $customers
     * @param  array<string, mixed>  $machines
     * @param  array<string, mixed>  $contactLeads
     */
    public function __construct(
        public readonly array $orders,
        public readonly array $revenue,
        public readonly array $maintenance,
        public readonly array $customers,
        public readonly array $machines,
        public readonly array $contactLeads,
        public readonly Carbon $generatedAt,
    ) {}
}
