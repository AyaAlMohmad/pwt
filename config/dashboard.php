<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Admin Dashboard Snapshot Cache
|--------------------------------------------------------------------------
|
| Phase 22 — caches the cross-domain KPI snapshot returned by
| `GET /api/v1/admin/dashboard`. The aggregation is cheap (~8 indexed
| COUNT queries) but the endpoint is the most-polled admin surface, so
| a 60 s TTL keeps the DB shielded from burst polling without making
| "this month" numbers feel stale.
|
| Set `cache_ttl` to `0` (or `DASHBOARD_CACHE_TTL=0`) to bypass the
| cache entirely — useful in feature tests so each call exercises the
| live computation path.
|
| See docs/architecture/admin-audit-templates-dashboard.md § 6.5.
*/

return [
    'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 60),
    'cache_key' => (string) env('DASHBOARD_CACHE_KEY', 'admin.dashboard.snapshot'),
];
