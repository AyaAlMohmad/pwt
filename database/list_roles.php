<?php

/**
 * List all role names only.
 *
 * Usage:
 *   php database/list_roles.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$roles = DB::table('roles')->orderBy('name')->pluck('name');

if ($roles->isEmpty()) {
    echo "No roles found.\n";
    exit(0);
}

echo "=== Available Roles ===\n";
foreach ($roles as $role) {
    echo "  - {$role}\n";
}

echo "\nDone.\n";
