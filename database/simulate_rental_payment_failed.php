<?php

/**
 * Simulate a failed rental payment for testing notifications.
 *
 * Usage:
 *   php database/simulate_rental_payment_failed.php <email> <machine_id>
 *   php database/simulate_rental_payment_failed.php aya.nader.almohamad@gmail.com 2017
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Enums\MachineRentalPaymentStatus;
use App\Events\Rentals\MachineRentalPaymentFailed;
use App\Models\MachineRentalPayment;
use App\Models\MachineRentalSchedule;
use Illuminate\Support\Facades\DB;

$email = $argv[1] ?? null;
$machineId = isset($argv[2]) ? (int) $argv[2] : null;

if ($email === null || $machineId === null) {
    echo "Usage: php database/simulate_rental_payment_failed.php <email> <machine_id>\n";
    exit(1);
}

$user = DB::table('users')->where('email', $email)->first();

if ($user === null) {
    echo "User not found: {$email}\n";
    exit(1);
}

echo "User: {$email} (ID: {$user->id})\n";

$schedule = DB::table('machine_rental_schedules')
    ->where('machine_id', $machineId)
    ->where('user_id', $user->id)
    ->first();

if ($schedule === null) {
    echo "No rental schedule found for machine #{$machineId} belonging to this user.\n";
    exit(1);
}

echo "Schedule found: #{$schedule->id}\n";

// Create a failed payment record.
$paymentId = DB::table('machine_rental_payments')->insertGetId([
    'machine_rental_schedule_id' => $schedule->id,
    'machine_id' => $machineId,
    'user_id' => $user->id,
    'payment_card_id' => $schedule->payment_card_id,
    'amount' => $schedule->amount,
    'currency' => $schedule->currency,
    'status' => MachineRentalPaymentStatus::Failed->value,
    'due_date' => now()->toDateString(),
    'paid_at' => null,
    'stripe_payment_intent_id' => 'pi_test_simulated_' . uniqid(),
    'failure_reason' => 'Simulated failure for testing notifications',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "Created failed payment record: #{$paymentId}\n";

// Dispatch the event to trigger notifications.
event(new MachineRentalPaymentFailed(
    paymentId: $paymentId,
    scheduleId: $schedule->id,
    machineId: $machineId,
    userId: (int) $user->id,
    amount: (string) $schedule->amount,
    currency: $schedule->currency,
    reason: 'Simulated failure for testing notifications',
    customerReason: 'Payment declined by bank (simulated)',
    customerName: $user->name,
    customerEmail: $user->email,
    machineName: 'Test Machine',
));

echo "Event dispatched: MachineRentalPaymentFailed\n";
echo "Notifications will be sent by the queue worker.\n";
echo "Check logs for details: storage/logs/laravel.log\n";
echo "\nDone.\n";
