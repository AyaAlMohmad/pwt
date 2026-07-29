<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;

/**
 * Outbound notification dispatcher per channel.
 *
 * Implementations MUST be idempotent — {@see SendNotificationJob}
 * may retry on transient failures and the queue is responsible for the
 * at-least-once delivery guarantee.
 *
 * Return true on hand-off success (the channel accepted responsibility
 * for the message), false on soft failure that does NOT warrant a retry
 * exception. Hard failures must throw — the job catches and marks the
 * row failed with the exception message.
 */
interface NotificationChannel
{
    public function send(Notification $notification): bool;
}
