<?php

declare(strict_types=1);

namespace App\Enums;

use App\Mail\GenericNotificationMail;

/**
 * Channel through which a notification is delivered.
 *
 * - `in_app`   — persisted in `notifications` and surfaced via the inbox endpoints.
 * - `sms`      — delegated to the existing Phase 3 `SmsDriver`.
 * - `email`    — queued via {@see GenericNotificationMail}.
 * - `whatsapp` — stub (logs only) until Phase 2 Meta WhatsApp Cloud wiring.
 * - `push`     — stub (logs only) until Phase 2 FCM/APNS wiring.
 *
 * Stored as a `string(16)` column in `notifications.channel` /
 * `notification_templates.channel`. See docs/architecture/notifications.md
 * § 10.1 / § 10.2.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Sms = 'sms';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Push = 'push';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
