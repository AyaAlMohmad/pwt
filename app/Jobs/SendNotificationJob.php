<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Notification;
use App\Services\Notifications\Channels\Drivers\InAppChannel;
use App\Services\Notifications\NotificationChannelRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hand-off job for a persisted {@see Notification} row.
 *
 * The dispatcher inserts the row in `queued` state; this job loads the
 * row, resolves the channel driver, calls `send()`, and updates
 * `delivery_status` + `sent_at` / `failure_reason` accordingly.
 *
 * Retries: 3 attempts with 30s backoff (Laravel multiplies). On
 * exhaustion the `failed()` hook marks the row failed with the
 * exception message (truncated to 480 chars).
 */
final class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $notificationId) {}

    public function handle(NotificationChannelRegistry $registry): void
    {
        /** @var Notification|null $notification */
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null) {
            Log::warning('notifications.job.row_missing', [
                'notification_id' => $this->notificationId,
            ]);

            return;
        }

        if ($notification->delivery_status !== DeliveryStatus::Queued) {
            // Idempotent — duplicate dispatch (retries, manual replays).
            return;
        }

        $channelEnum = $notification->channel;
        $driver = $registry->for($channelEnum);

        try {
            $ok = $driver->send($notification);

            if (! $ok) {
                $notification->forceFill([
                    'delivery_status' => DeliveryStatus::Failed->value,
                    'failure_reason' => 'channel_returned_false',
                ])->save();

                return;
            }

            $isInApp = $driver instanceof InAppChannel;
            $notification->forceFill([
                'delivery_status' => $isInApp
                    ? DeliveryStatus::Delivered->value
                    : DeliveryStatus::Sent->value,
                'sent_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $e) {
            $notification->forceFill([
                'delivery_status' => DeliveryStatus::Failed->value,
                'failure_reason' => Str::limit($e->getMessage(), 480, ''),
            ])->save();

            throw $e;
        }
    }

    /**
     * Last-resort guard if retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Notification::query()
            ->where('id', $this->notificationId)
            ->update([
                'delivery_status' => DeliveryStatus::Failed->value,
                'failure_reason' => Str::limit($exception->getMessage(), 480, ''),
            ]);

        Log::error('notifications.job.failed', [
            'notification_id' => $this->notificationId,
            'message' => $exception->getMessage(),
        ]);
    }
}
