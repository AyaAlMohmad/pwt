<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Models\Notification;
use App\Services\Notifications\Channels\NotificationChannel;

/**
 * In-app channel. No external hand-off — the persisted
 * {@see Notification} row IS the artefact the customer sees via
 * `GET /notifications`. The job marks the row delivered immediately
 * after this no-op send returns.
 */
final class InAppChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        unset($notification);

        return true;
    }
}
