<?php

/**
 * Set a rented machine's next payment due date to X days from now
 * by customer email.
 *
 * Usage:
 *   php database/set_rental_due_date.php <email> <days_from_now>
 *   php database/set_rental_due_date.php aya.nader.almohamad@gmail.com 5
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$email = $argv[1] ?? null;
$days = isset($argv[2]) ? (int) $argv[2] : 5;

if ($email === null) {
    echo "Usage: php database/set_rental_due_date.php <email> <days_from_now>\n";
    exit(1);
}

$user = DB::table('users')->where('email', $email)->first();

if ($user === null) {
    echo "User not found: {$email}\n";
    exit(1);
}

echo "User: {$email} (ID: {$user->id})\n";

$machines = DB::table('machines')
    ->where('user_id', $user->id)
    ->where('term', 'rent')
    ->get();

if ($machines->isEmpty()) {
    echo "No rented machines found for this user.\n";
    exit(1);
}

$dueDate = now()->addDays($days)->toDateString();

foreach ($machines as $machine) {
    $schedule = DB::table('machine_rental_schedules')
        ->where('machine_id', $machine->id)
        ->first();

    if ($schedule === null) {
        echo "  Machine #{$machine->id}: no rental schedule found.\n";
        continue;
    }

    $oldDue = $schedule->next_payment_due_at;
    DB::table('machine_rental_schedules')
        ->where('id', $schedule->id)
        ->update([
            'next_payment_due_at' => $dueDate,
            'reminder_sent_at' => null, // reset so reminder fires again
        ]);

    echo "  Machine #{$machine->id}: due date changed from {$oldDue} to {$dueDate}\n";
}

echo "\nDone.\n";
