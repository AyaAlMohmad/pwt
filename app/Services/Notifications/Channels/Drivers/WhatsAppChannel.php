<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Models\Notification;
use App\Services\Notifications\Channels\NotificationChannel;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp stub. Logs the dispatch and returns true. Real Meta WhatsApp
 * Cloud wiring is Phase 2 — swapping the body for the production
 * implementation is a one-class change.
 */
final class WhatsAppChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        Log::info('notifications.whatsapp.stub_send', [
            'notification_id' => $notification->id,
            'user_id' => $notification->user_id,
            'type' => $notification->type,
        ]);

        return true;
    }
}
