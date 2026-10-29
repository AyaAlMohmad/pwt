<?php

/**
 * Send a test push notification to user Aya.
 *
 * Usage:
 *   php database/send_test_push_to_aya.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$email = 'aya.nader.almohamad@gmail.com';

// ------------------------------------------------------------------
// 1. Find or create admin user Aya
// ------------------------------------------------------------------
/** @var User|null $userModel */
$userModel = User::query()->where('email', $email)->first();

if ($userModel === null) {
    $userId = (string) Str::uuid();
    $userModel = User::query()->create([
        'id' => $userId,
        'name' => 'Aya Nader Almohamad',
        'email' => $email,
        'mobile' => '0500000000',
        'country_code' => '+971',
        'language' => 'en',
        'is_admin' => true,
    ]);
    echo "User created: {$userId}\n";
} else {
    $userId = $userModel->id;
    echo "User found: {$userId}\n";
}

// Ensure the user has an admin role so admin notifications reach them.
if (! $userModel->hasRole('super_admin')) {
    $userModel->assignRole('super_admin');
    echo "Role assigned: super_admin\n";
} else {
    echo "Role already assigned: super_admin\n";
}

// ------------------------------------------------------------------
// 2. Register the real device token (from Firefox on Windows)
// ------------------------------------------------------------------
$testToken = 'elr0VLwW80TTY_To4tpc0:APA91bTfzePJN0DH3vBSiR-Gem16xRiBXDS4ToSV_z39kCbiU7izzAPrW1ygn0tctQMQV4w-XldGHo_FhaTIZ-KBzbahpUqigsbwi1ZmrlDhiGFMpT7VFRV';

UserDeviceToken::updateOrCreate(
    [
        'user_id' => $userId,
        'device_token' => $testToken,
    ],
    [
        'platform' => 'web',
        'device_name' => 'Firefox on Windows',
        'app_version' => '1.0.0',
        'is_active' => true,
        'last_used_at' => now(),
    ]
);

echo "Real device token registered: {$testToken}\n";

// ------------------------------------------------------------------
// 3. Create a push notification row
// ------------------------------------------------------------------
$notificationId = DB::table('notifications')->insertGetId([
    'public_id' => 'NOT-' . strtoupper(Str::random(8)),
    'user_id' => $userId,
    'channel' => NotificationChannel::Push->value,
    'type' => 'rental.payment_due_admin',
    'title' => json_encode([
        'en' => 'Rent due in 5 days',
        'ar' => 'الإيجار مستحق خلال 5 أيام',
    ]),
    'body' => json_encode([
        'en' => 'Your rental payment for Machine #12 is due soon.',
        'ar' => 'دفع الإيجار للجهاز #12 مستحق قريباً.',
    ]),
    'action_url' => null,
    'data' => json_encode([
        'machine_id' => 'MCH-2026-0001',
        'amount' => '150.00',
        'currency' => 'AED',
    ]),
    'delivery_status' => DeliveryStatus::Queued->value,
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "Notification created: {$notificationId}\n";

// ------------------------------------------------------------------
// 4. Run the job synchronously (immediate test)
// ------------------------------------------------------------------
echo "Sending push notification...\n";

$job = new SendNotificationJob($notificationId);
$job->handle(app(App\Services\Notifications\NotificationChannelRegistry::class));

// ------------------------------------------------------------------
// 5. Show result
// ------------------------------------------------------------------
$n = DB::table('notifications')->find($notificationId);

echo "\n=== RESULT ===\n";
echo "Status: {$n->delivery_status}\n";
echo "Sent at: " . ($n->sent_at ?? '-') . "\n";
echo "Failure reason: " . ($n->failure_reason ?? '-') . "\n";

echo "\nCheck logs for FCM response:\n";
echo "   storage/logs/laravel.log\n";
