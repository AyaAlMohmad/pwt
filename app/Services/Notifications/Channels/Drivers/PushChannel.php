<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Models\Notification;
use App\Services\Notifications\Channels\NotificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * Push stub. Logs the dispatch and returns true. FCM / APNS wiring is
 * Phase 2 — see docs/architecture/notifications.md § 5.3.2 and Open Q 3.
 */
final class PushChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        Log::info('notifications.push.stub_send', [
            'notification_id' => $notification->id,
            'user_id' => $notification->user_id,
            'type' => $notification->type,
        ]);

        return true;
    }
}
