<?php

/**
 * Set a rented machine's next payment due date to today (or X days from now)
 * and optionally trigger the payment processor.
 *
 * Usage:
 *   php database/set_rental_due_date.php <machine_id> [days_from_now]
 *   php database/set_rental_due_date.php 2017 0   # due today → triggers payment
 *   php database/set_rental_due_date.php 2017 5   # 5-day reminder
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$machineId = isset($argv[1]) ? (int) $argv[1] : null;
$days = isset($argv[2]) ? (int) $argv[2] : 0;

if ($machineId === null) {
    echo "Usage: php database/set_rental_due_date.php <machine_id> [days_from_now]\n";
    echo "  days=0 → due today (triggers payment processing)\n";
    echo "  days=5 → 5-day reminder\n";
    exit(1);
}

$machine = DB::table('machines')->where('id', $machineId)->first();

if ($machine === null) {
    echo "Machine not found: #{$machineId}\n";
    exit(1);
}

if ($machine->term !== 'rent') {
    echo "Machine #{$machineId} is not a rental (term: {$machine->term}).\n";
    exit(1);
}

$schedule = DB::table('machine_rental_schedules')
    ->where('machine_id', $machineId)
    ->first();

if ($schedule === null) {
    echo "Machine #{$machineId}: no rental schedule found.\n";
    exit(1);
}

$dueDate = now()->addDays($days)->toDateString();
$oldDue = $schedule->next_payment_due_at;

DB::table('machine_rental_schedules')
    ->where('id', $schedule->id)
    ->update([
        'next_payment_due_at' => $dueDate,
        'reminder_sent_at' => null,
    ]);

echo "Machine #{$machineId}: due date changed from {$oldDue} to {$dueDate}\n";

// If due today, offer to run the payment processor.
if ($days === 0) {
    echo "\nPayment is due today. Run payment processor?\n";
    echo "Command: php artisan rental:process-payments\n";
}

echo "\nDone.\n";
