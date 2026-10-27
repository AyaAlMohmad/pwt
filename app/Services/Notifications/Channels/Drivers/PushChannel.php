<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Drivers;

use App\Models\Notification;
use App\Models\UserDeviceToken;
use App\Services\Notifications\Channels\NotificationChannel;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * Firebase Cloud Messaging (FCM) push-notification driver.
 *
 * Uses the FCM HTTP v1 API via kreait/firebase-php with a service-account JSON.
 * The legacy FCM_SERVER_KEY endpoint (https://fcm.googleapis.com/fcm/send) is
 * deprecated and no longer functional.
 */
final class PushChannel implements NotificationChannel
{
    public function send(Notification $notification): bool
    {
        $tokens = UserDeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->pluck('device_token')
            ->toArray();

        if ($tokens === []) {
            Log::info('notifications.push.no_tokens', [
                'user_id' => $notification->user_id,
            ]);

            return true; // Nothing to send, but not a failure.
        }

        $credentialsPath = config('services.fcm.credentials', '');
        if ($credentialsPath === '' || ! file_exists($credentialsPath)) {
            Log::warning('notifications.push.missing_credentials', [
                'user_id' => $notification->user_id,
                'path' => $credentialsPath,
            ]);

            return false;
        }

        $title = $this->resolveText($notification->title, 'title');
        $body = $this->resolveText($notification->body, 'body');

        $data = [
            'notification_id' => $notification->public_id,
            'type' => $notification->type,
            'action_url' => $notification->action_url ?? '',
        ];

        if ($notification->data !== null) {
            foreach ($notification->data as $k => $v) {
                $data[$k] = is_scalar($v) ? (string) $v : json_encode($v);
            }
        }

        try {
            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::error('notifications.push.firebase_init_failed', [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        $okCount = 0;
        foreach ($tokens as $token) {
            $message = CloudMessage::new()
                ->withToken($token)
                ->withNotification(FcmNotification::create($title, $body))
                ->withData($data)
                ->withHighestPossiblePriority();

            try {
                $messaging->send($message);
                $okCount++;
            } catch (NotFound|InvalidMessage $e) {
                // Token is dead (UNREGISTERED / INVALID_ARGUMENT) — deactivate it.
                UserDeviceToken::query()
                    ->where('device_token', $token)
                    ->update(['is_active' => false]);

                Log::warning('notifications.push.token_dead', [
                    'token' => substr($token, 0, 20),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('notifications.push.send_failed', [
                    'token' => substr($token, 0, 20),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $okCount > 0;
    }

    /**
     * Extract a single locale string from a bilingual (en/ar) column.
     */
    private function resolveText(mixed $value, string $fallback): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (string) ($value['en'] ?? $value['ar'] ?? $value[array_key_first($value)] ?? '');
        }

        return (string) $value;
    }
}
