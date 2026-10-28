<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Events\BaseDomainEvent;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Persists a {@see Notification} row per (recipient × channel) and
 * dispatches a {@see SendNotificationJob} to hand the row off to its
 * channel driver.
 *
 * Responsibilities:
 *   - Resolve channels for an event type via config.
 *   - Look up `notification_templates` per (key, channel).
 *   - Render bilingual title + body via {@see TemplateRenderer}.
 *   - Apply duplicate-suppression (same user × type × primary business
 *     id within the configured window).
 *   - Persist the row and enqueue the job.
 *
 * NOT responsible for the actual transport — that lives in the channel
 * driver behind the queued job.
 *
 * See docs/architecture/notifications.md § 5.2 / § 5.5.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, NotificationChannelEnum>|null  $overrideChannels
     * @return array<int, Notification>
     */
    public function dispatch(
        string $type,
        User $recipient,
        array $data,
        ?array $overrideChannels = null,
    ): array {
        $channels = $overrideChannels ?? $this->channelsForType($type);

        if ($channels === []) {
            return [];
        }

        $businessId = $this->primaryBusinessId($data);
        if ($this->isDuplicate((int) $recipient->id, $type, $businessId)) {
            Log::debug('notifications.dispatch.deduped', [
                'type' => $type,
                'user_id' => $recipient->id,
                'business_id' => $businessId,
            ]);

            return [];
        }

        $persisted = [];
        foreach ($channels as $channel) {
            /** @var NotificationTemplate|null $template */
            $template = NotificationTemplate::query()
                ->where('key', $type)
                ->where('channel', $channel->value)
                ->where('is_active', true)
                ->first();

            if ($template === null) {
                Log::warning('notifications.dispatch.template_missing', [
                    'type' => $type,
                    'channel' => $channel->value,
                ]);

                continue;
            }

            $context = $this->buildContext($recipient, $data);
            $rendered = $this->renderer->renderBilingual($template, $context);

            $notification = Notification::query()->create([
                'public_id' => Str::uuid()->toString(),
                'user_id' => $recipient->id,
                'type' => $type,
                'title' => $rendered['title'],
                'body' => $rendered['body'],
                'data' => $this->safeScalars($data),
                'action_url' => $this->resolveActionUrl($type, $data),
                'channel' => $channel->value,
                'delivery_status' => DeliveryStatus::Queued->value,
            ]);

            $queue = (string) config('notifications.queue', 'notifications');
            SendNotificationJob::dispatch($notification->id)->onQueue($queue);

            $persisted[] = $notification;
        }

        return $persisted;
    }

    /**
     * Fan-out for a domain event. Returns the persisted Notification
     * rows so the listener can log how many channels fired.
     *
     * @return array<int, Notification>
     */
    public function dispatchFor(BaseDomainEvent $event): array
    {
        $payload = $event->payload();
        $userId = isset($payload['user_id']) && is_int($payload['user_id']) ? $payload['user_id'] : null;

        if ($userId === null) {
            // Auth events (OTP for pre-registration etc.) have no user_id
            // → orchestrator decision 2 says no-op. Listener handles the
            // skip but we double-check here for defence-in-depth.
            return [];
        }

        /** @var User|null $recipient */
        $recipient = User::query()->find($userId);
        if ($recipient === null) {
            Log::warning('notifications.dispatch.recipient_missing', [
                'user_id' => $userId,
                'type' => $event->templateKey(),
            ]);

            return [];
        }

        return $this->dispatch(
            type: $event->templateKey(),
            recipient: $recipient,
            data: $payload,
        );
    }

    /**
     * @return array<int, NotificationChannelEnum>
     */
    private function channelsForType(string $type): array
    {
        /** @var array<string, list<string>> $map */
        $map = (array) config('notifications.channels_per_type', []);
        $channels = $map[$type] ?? [];

        $out = [];
        foreach ($channels as $value) {
            $candidate = NotificationChannelEnum::tryFrom((string) $value);
            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * Suppress dispatch if the same (user, type, business_id) tuple has
     * been persisted within `notifications.duplicate_window_seconds`.
     * Returns true → caller skips.
     */
    private function isDuplicate(int $userId, string $type, ?int $businessId): bool
    {
        $window = (int) config('notifications.duplicate_window_seconds', 30);
        if ($window <= 0) {
            return false;
        }

        $cutoff = Carbon::now()->subSeconds($window);

        $query = Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('created_at', '>=', $cutoff);

        if ($businessId !== null) {
            // Match on the data JSON column for the same primary business id.
            $query->where(static function ($q) use ($businessId): void {
                $q->where('data->order_id', $businessId)
                    ->orWhere('data->maintenance_request_id', $businessId)
                    ->orWhere('data->payment_id', $businessId);
            });
        }

        return $query->exists();
    }

    /**
     * Pick the primary business id from a payload. Order takes priority
     * over payment over maintenance_request; this is purely a dedupe
     * heuristic and never affects what gets persisted.
     *
     * @param  array<string, mixed>  $data
     */
    private function primaryBusinessId(array $data): ?int
    {
        foreach (['order_id', 'maintenance_request_id', 'payment_id', 'machine_id', 'schedule_id'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return null;
    }

    /**
     * Build the context array that the renderer substitutes against.
     * Nested under `user` + `data` so templates can use
     * `{{user.name}}` and `{{data.order_id}}`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildContext(User $recipient, array $data): array
    {
        return [
            'user' => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
            ],
            'data' => $data,
            // Promote frequent paths so authors can write
            // {{order.public_id}} instead of {{data.order_public_id}}.
            'order' => $this->extractScope($data, 'order_id', 'order_public_id'),
            'payment' => $this->extractScope($data, 'payment_id', 'payment_public_id'),
            'maintenance' => $this->extractScope($data, 'maintenance_request_id', 'maintenance_public_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractScope(array $data, string $idKey, string $publicIdKey): array
    {
        return [
            'id' => $data[$idKey] ?? null,
            'public_id' => $data[$publicIdKey] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveActionUrl(string $type, array $data): ?string
    {
        if (str_ends_with($type, '_admin')) {
            return rtrim((string) config('app.urlappadmin', ''), '/');
        }

        // Customer notifications open the notifications inbox on tap.
        return null;
    }

    /**
     * Strip closures / models / resources from the data blob so the
     * JSON column never holds unsafe values.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function safeScalars(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            } elseif (is_array($value)) {
                $out[$key] = $this->safeScalars($value);
            }
            // Drop everything else silently — closures, objects, models.
        }

        return $out;
    }
}
