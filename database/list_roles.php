<?php

/**
 * List all roles and their assigned users.
 *
 * Usage:
 *   php database/list_roles.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$roles = DB::table('roles')->orderBy('name')->get();

if ($roles->isEmpty()) {
    echo "No roles found.\n";
    exit(0);
}

foreach ($roles as $role) {
    echo "\n=== Role: {$role->name} ===\n";

    $users = DB::table('users')
        ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
        ->where('model_has_roles.role_id', $role->id)
        ->where('model_has_roles.model_type', 'App\Models\User')
        ->select('users.id', 'users.name', 'users.email', 'users.is_admin')
        ->get();

    if ($users->isEmpty()) {
        echo "  (no users)\n";
        continue;
    }

    foreach ($users as $user) {
        $adminBadge = $user->is_admin ? ' [ADMIN]' : '';
        echo "  - {$user->name} ({$user->email}){$adminBadge}\n";
    }
}

echo "\nDone.\n";
