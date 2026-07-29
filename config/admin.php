<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Admin Sanctum Token TTL (hours)
    |--------------------------------------------------------------------------
    |
    | Lifetime of an admin Sanctum access token, measured in hours.
    | Defaults to 8 — a single GCC working day — to bound the blast
    | radius of an unattended dashboard session. The shorter TTL is the
    | primary distinguisher between admin and customer tokens (customer
    | tokens are 30 days per CLAUDE.md § 16.12).
    |
    | docs/architecture/admin-auth.md § 9.3.
    */
    'token_ttl_hours' => (int) env('ADMIN_TOKEN_TTL_HOURS', 8),
];
