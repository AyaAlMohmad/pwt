<?php

/**
 * Set a user's role.
 *
 * Usage:
 *   php database/set_user_role.php aya.nader.almohamad@gmail.com customer
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;

$email = $argv[1] ?? null;
$role = $argv[2] ?? null;

if ($email === null || $role === null) {
    echo "Usage: php database/set_user_role.php <email> <role>\n";
    exit(1);
}

/** @var User|null $user */
$user = User::withTrashed()->where('email', $email)->first();

if ($user === null) {
    echo "User not found: {$email}\n";
    exit(1);
}

try {
    $user->syncRoles([$role]);
    echo "Role set to '{$role}' for {$email}\n";
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    exit(1);
}
