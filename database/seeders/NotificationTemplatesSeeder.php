<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Phase 11 — seed the notification_templates table with copy for every
 * (event key × channel) pair wired in `config/notifications.php`.
 *
 * Idempotent on `(key, channel)` via `updateOrCreate` so repeated runs
 * never duplicate rows. Bilingual (EN + AR) copy lives in
 * `lang/{en,ar}/notifications.php` under `templates.*` — this seeder
 * pulls from there so admin Phase 22 can later show "reset to default".
 */
final class NotificationTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, list<string>> $channelsPerType */
        $channelsPerType = (array) config('notifications.channels_per_type', []);

        foreach ($channelsPerType as $key => $channels) {
            foreach ($channels as $channelValue) {
                $channel = NotificationChannel::tryFrom((string) $channelValue);
                if ($channel === null) {
                    continue;
                }

                $copy = $this->copyFor($key);

                NotificationTemplate::query()->updateOrCreate(
                    ['key' => $key, 'channel' => $channel->value],
                    [
                        'subject' => [
                            'en' => $copy['en']['subject'],
                            'ar' => $copy['ar']['subject'],
                        ],
                        'body' => [
                            'en' => $copy['en']['body'],
                            'ar' => $copy['ar']['body'],
                        ],
                        'placeholders' => $this->placeholdersFor($key),
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * Pull EN + AR copy from the lang files for the given template key.
     *
     * @return array{en: array{subject: string, body: string}, ar: array{subject: string, body: string}}
     */
    private function copyFor(string $key): array
    {
        // Convert `order.confirmed` → `templates.order.confirmed`.
        $path = 'notifications.templates.'.$key;

        /** @var array<string, mixed> $en */
        $en = (array) trans($path, [], 'en');
        /** @var array<string, mixed> $ar */
        $ar = (array) trans($path, [], 'ar');

        return [
            'en' => [
                'subject' => isset($en['subject']) && is_string($en['subject']) ? $en['subject'] : ucfirst($key),
                'body' => isset($en['body']) && is_string($en['body']) ? $en['body'] : '',
            ],
            'ar' => [
                'subject' => isset($ar['subject']) && is_string($ar['subject']) ? $ar['subject'] : ucfirst($key),
                'body' => isset($ar['body']) && is_string($ar['body']) ? $ar['body'] : '',
            ],
        ];
    }

    /**
     * Document the supported placeholder names per template key so the
     * Phase 22 admin UI can render an autocomplete picker.
     *
     * @return list<string>
     */
    private function placeholdersFor(string $key): array
    {
        $base = ['user.id', 'user.name', 'user.email'];

        if (str_starts_with($key, 'order.')) {
            return array_merge($base, ['order.id', 'order.public_id']);
        }
        if (str_starts_with($key, 'payment.')) {
            return array_merge($base, ['order.id', 'payment.id']);
        }
        if (str_starts_with($key, 'maintenance.')) {
            return array_merge($base, ['maintenance.id', 'maintenance.public_id']);
        }
        if (str_starts_with($key, 'support.')) {
            return array_merge($base, ['data.support_ticket_id']);
        }
        if (str_starts_with($key, 'contact.')) {
            // Phase 13 — ops-only; no user context, just `lead.*` keys.
            return [
                'lead.id',
                'lead.name',
                'lead.email',
                'lead.phone',
                'lead.subject',
                'lead.message',
                'lead.source',
            ];
        }
        if (str_starts_with($key, 'rental.')) {
            return array_merge($base, [
                'data.schedule_id',
                'data.machine_id',
                'data.machine_name',
                'data.user_name',
                'data.user_email',
                'data.due_date',
                'data.amount',
                'data.currency',
                'data.reason',
            ]);
        }

        return $base;
    }
}
