<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\Channels\NotificationChannel;
use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Facades\Log;

/**
 * SMS channel — delegates to the existing Phase 3 {@see SmsDriver}
 * stub (production drivers are wired but disabled).
 *
 * The body sent over SMS is the locale-rendered `notifications.body`
 * (single-string, already substituted by {@see TemplateRenderer} when
 * the row was persisted). PII (phone number) is masked in logs per
 * CLAUDE.md § 16.11.
 */
final class SmsChannel implements NotificationChannel
{
    public function __construct(
        private readonly SmsDriver $sms,
    ) {}

    public function send(Notification $notification): bool
    {
        /** @var User|null $user */
        $user = User::query()->find($notification->user_id);

        if ($user === null || $user->phone === null || $user->phone === '') {
            Log::warning('notifications.sms.skipped_no_phone', [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
            ]);

            return false;
        }

        $countryCode = $user->phone_country_code;
        $to = str_starts_with($countryCode, '+')
            ? $countryCode.$user->phone
            : '+'.$countryCode.$user->phone;

        $locale = $user->locale !== '' ? $user->locale : 'en';
        $body = (string) $notification->getTranslation('body', $locale);

        Log::info('NOTIFICATION_SMS_SEND_START', [
            'to' => $to,
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'driver_class' => get_class($this->sms),
        ]);

        $this->sms->send(
            to: $to,
            message: $body,
            context: [
                'notification_id' => $notification->id,
                'type' => $notification->type,
            ],
        );

        Log::info('NOTIFICATION_SMS_SEND_OK', [
            'to' => $to,
            'notification_id' => $notification->id,
            'type' => $notification->type,
        ]);

        return true;
    }
}
