<?php

/**
 * Send test push notifications to both customer and admin.
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

$testToken = 'elr0VLwW80TTY_To4tpc0:APA91bTfzePJN0DH3vBSiR-Gem16xRiBXDS4ToSV_z39kCbiU7izzAPrW1ygn0tctQMQV4w-XldGHo_FhaTIZ-KBzbahpUqigsbwi1ZmrlDhiGFMpT7VFRV';

// ------------------------------------------------------------------
// Helper: find/create user and register token
// ------------------------------------------------------------------
function ensureUser(string $email, string $name, bool $isAdmin): User
{
    $user = User::query()->where('email', $email)->first();

    if ($user === null) {
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'mobile' => '0500000000',
            'country_code' => '+971',
            'language' => 'en',
            'is_admin' => $isAdmin,
            'password' => bcrypt('password'),
        ]);
        echo "User created: {$email} ({$user->id})\n";
    } else {
        echo "User found: {$email} ({$user->id})\n";
    }

    return $user;
}

function ensureRole(User $user, string $role): void
{
    if (! $user->hasRole($role)) {
        $user->assignRole($role);
        echo "Role assigned: {$role} to {$user->email}\n";
    } else {
        echo "Role already assigned: {$role} to {$user->email}\n";
    }
}

function registerToken(User $user, string $token): void
{
    UserDeviceToken::updateOrCreate(
        [
            'user_id' => $user->id,
            'device_token' => $token,
        ],
        [
            'platform' => 'web',
            'device_name' => 'Firefox on Windows',
            'app_version' => '1.0.0',
            'is_active' => true,
            'last_used_at' => now(),
        ]
    );
    echo "Token registered for {$user->email}\n";
}

function sendPush(int $userId, string $type, array $title, array $body, array $data): int
{
    $notificationId = DB::table('notifications')->insertGetId([
        'public_id' => 'NOT-' . strtoupper(Str::random(8)),
        'user_id' => $userId,
        'channel' => NotificationChannel::Push->value,
        'type' => $type,
        'title' => json_encode($title),
        'body' => json_encode($body),
        'action_url' => null,
        'data' => json_encode($data),
        'delivery_status' => DeliveryStatus::Queued->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new SendNotificationJob($notificationId);
    $job->handle(app(App\Services\Notifications\NotificationChannelRegistry::class));

    return $notificationId;
}

// ------------------------------------------------------------------
// 1. Customer
// ------------------------------------------------------------------
$customer = ensureUser('aya.nader.almohamad@gmail.com', 'Aya Nader Almohamad', false);
registerToken($customer, $testToken);

// ------------------------------------------------------------------
// 2. Admin
// ------------------------------------------------------------------
$admin = ensureUser('admin@pwt.local', 'Admin User', true);
$role = $admin->hasRole('super_admin') ? 'super_admin' : 'operations';
ensureRole($admin, $role);
registerToken($admin, $testToken);

echo "\n=== SENDING NOTIFICATIONS ===\n";

// ------------------------------------------------------------------
// 3. Customer notification (rental.payment_due)
// ------------------------------------------------------------------
$customerNotificationId = sendPush(
    userId: (int) $customer->id,
    type: 'rental.payment_due',
    title: [
        'en' => 'Rent due in 5 days',
        'ar' => 'الإيجار مستحق خلال 5 أيام',
    ],
    body: [
        'en' => 'Your rental payment for Machine #12 is due soon.',
        'ar' => 'دفع الإيجار للجهاز #12 مستحق قريباً.',
    ],
    data: [
        'machine_id' => 'MCH-2026-0001',
        'amount' => '150.00',
        'currency' => 'AED',
    ]
);

// ------------------------------------------------------------------
// 4. Admin notification (rental.payment_due_admin)
// ------------------------------------------------------------------
$adminNotificationId = sendPush(
    userId: (int) $admin->id,
    type: 'rental.payment_due_admin',
    title: [
        'en' => 'Upcoming Rental Payment',
        'ar' => 'دفعة إيجار قادمة',
    ],
    body: [
        'en' => 'The rented machine #MCH-2026-0001 for customer Aya Nader Almohamad has a payment due on 2026-11-03.',
        'ar' => 'الجهاز المستأجر #MCH-2026-0001 للعميل آية نادر المحمد يستحق الدفع في 2026-11-03.',
    ],
    data: [
        'machine_id' => 'MCH-2026-0001',
        'machine_name' => 'RO System 500L',
        'due_date' => '2026-11-03',
        'amount' => '150.00',
        'currency' => 'AED',
        'customer_name' => 'Aya Nader Almohamad',
        'customer_email' => 'aya.nader.almohamad@gmail.com',
    ]
);

// ------------------------------------------------------------------
// 5. Show results
// ------------------------------------------------------------------
function showResult(int $notificationId, string $label): void
{
    $n = DB::table('notifications')->find($notificationId);
    echo "\n=== {$label} ===\n";
    echo "Status: {$n->delivery_status}\n";
    echo "Sent at: " . ($n->sent_at ?? '-') . "\n";
    echo "Failure reason: " . ($n->failure_reason ?? '-') . "\n";
}

showResult($customerNotificationId, 'CUSTOMER (rental.payment_due)');
showResult($adminNotificationId, 'ADMIN (rental.payment_due_admin)');

echo "\nCheck logs for FCM response:\n";
echo "   storage/logs/laravel.log\n";
