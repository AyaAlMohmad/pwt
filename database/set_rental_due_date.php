<?php

/**
 * Set a machine rental schedule due date to X days from now.
 *
 * Usage:
 *   php database/set_rental_due_date.php <order_public_id> <days_from_now>
 *   php database/set_rental_due_date.php PWT-0187 5
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Machine;
use App\Models\MachineRentalSchedule;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

$orderPublicId = $argv[1] ?? null;
$days = isset($argv[2]) ? (int) $argv[2] : 5;

if ($orderPublicId === null) {
    echo "Usage: php database/set_rental_due_date.php <order_public_id> <days_from_now>\n";
    exit(1);
}

$order = Order::query()->where('public_id', $orderPublicId)->first();

if ($order === null) {
    echo "Order not found: {$orderPublicId}\n";
    exit(1);
}

echo "Order: {$order->public_id} (ID: {$order->id})\n";

// Find rented machines from this order.
$machines = Machine::query()
    ->where('user_id', $order->user_id)
    ->where('term', 'rent')
    ->get();

if ($machines->isEmpty()) {
    echo "No rented machines found for this order.\n";
    exit(1);
}

$dueDate = now()->addDays($days)->toDateString();

foreach ($machines as $machine) {
    $schedule = MachineRentalSchedule::query()
        ->where('machine_id', $machine->id)
        ->first();

    if ($schedule === null) {
        echo "  Machine #{$machine->id}: no rental schedule found.\n";
        continue;
    }

    $oldDue = $schedule->next_payment_due_at->toDateString();
    $schedule->update([
        'next_payment_due_at' => $dueDate,
        'reminder_sent_at' => null, // reset so reminder fires again
    ]);

    echo "  Machine #{$machine->id}: due date changed from {$oldDue} to {$dueDate}\n";
}

echo "\nDone.\n";
