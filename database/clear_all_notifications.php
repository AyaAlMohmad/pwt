<?php

/**
 * Delete ALL notifications for ALL users (customers + admins).
 *
 * WARNING: This is a DESTRUCTIVE operation. It truncates the
 * `notifications` table and resets related tokens if requested.
 *
 * Usage:
 *   php database/clear_all_notifications.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

// ------------------------------------------------------------------
// Show stats before delete
// ------------------------------------------------------------------
$total = DB::table('notifications')->count();
$byChannel = DB::table('notifications')
    ->select('channel', DB::raw('COUNT(*) as count'))
    ->groupBy('channel')
    ->pluck('count', 'channel')
    ->toArray();

$byType = DB::table('notifications')
    ->select('type', DB::raw('COUNT(*) as count'))
    ->groupBy('type')
    ->pluck('count', 'type')
    ->toArray();

$byUser = DB::table('notifications')
    ->select('user_id', DB::raw('COUNT(*) as count'))
    ->groupBy('user_id')
    ->count();

echo "=== BEFORE DELETE ===\n";
echo "Total notifications: {$total}\n";
echo "Unique users with notifications: {$byUser}\n";
echo "By channel:\n";
foreach ($byChannel as $ch => $c) {
    echo "  {$ch}: {$c}\n";
}
echo "By type (top 10):\n";
arsort($byType);
$top = array_slice($byType, 0, 10, true);
foreach ($top as $t => $c) {
    echo "  {$t}: {$c}\n";
}

// ------------------------------------------------------------------
// Confirm
// ------------------------------------------------------------------
$force = in_array('--force', $argv ?? [], true);

if (! $force) {
    echo "\n⚠️  WARNING: This will DELETE ALL notifications permanently.\n";
    echo "Run with --force to confirm, or type 'yes' below:\n";
    echo "Type 'yes' to continue: ";
    $handle = fopen('php://stdin', 'r');
    $line = fgets($handle);
    fclose($handle);

    if (trim($line) !== 'yes') {
        echo "Aborted.\n";
        exit(1);
    }
}

// ------------------------------------------------------------------
// Delete
// ------------------------------------------------------------------
$deleted = DB::table('notifications')->delete();

echo "\n=== AFTER DELETE ===\n";
echo "Deleted rows: {$deleted}\n";
echo "Remaining notifications: " . DB::table('notifications')->count() . "\n";
echo "Done.\n";
