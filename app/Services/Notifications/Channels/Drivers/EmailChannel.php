<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\Channels\NotificationChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email channel — queues the {@see GenericNotificationMail} via the
 * default mail transport. The Mailable itself implements ShouldQueue
 * but we explicitly call `Mail::queue` so test-side `Mail::fake()` +
 * `Mail::assertQueued()` works cleanly.
 *
 * Skips silently (returns false) when the recipient has no email — the
 * job marks the row failed with reason "no_recipient_email" so the
 * admin observability surface can highlight delivery gaps without
 * exception spam.
 */
final class EmailChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        /** @var User|null $user */
        $user = User::query()->find($notification->user_id);

        if ($user === null || $user->email === null || $user->email === '') {
            Log::warning('notifications.email.skipped_no_address', [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
            ]);

            return false;
        }

        Mail::to($user->email)->queue(new GenericNotificationMail($notification));

        return true;
    }
}
