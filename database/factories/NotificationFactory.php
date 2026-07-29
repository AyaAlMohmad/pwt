<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'type' => 'order.confirmed',
            'title' => [
                'en' => 'Your order is confirmed',
                'ar' => 'تم تأكيد طلبك',
            ],
            'body' => [
                'en' => 'Order #ABC123 has been confirmed.',
                'ar' => 'تم تأكيد الطلب رقم ABC123.',
            ],
            'data' => ['order_id' => 1, 'order_public_id' => Str::uuid()->toString()],
            'action_url' => null,
            'channel' => NotificationChannel::InApp->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'failure_reason' => null,
            'read_at' => null,
            'sent_at' => now(),
        ];
    }

    public function unread(): self
    {
        return $this->state(fn (array $attrs): array => [
            'read_at' => null,
        ]);
    }

    public function read(): self
    {
        return $this->state(fn (array $attrs): array => [
            'read_at' => now(),
        ]);
    }

    public function inApp(): self
    {
        return $this->state(fn (array $attrs): array => [
            'channel' => NotificationChannel::InApp->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);
    }

    public function email(): self
    {
        return $this->state(fn (array $attrs): array => [
            'channel' => NotificationChannel::Email->value,
            'delivery_status' => DeliveryStatus::Sent->value,
        ]);
    }

    public function sms(): self
    {
        return $this->state(fn (array $attrs): array => [
            'channel' => NotificationChannel::Sms->value,
            'delivery_status' => DeliveryStatus::Sent->value,
        ]);
    }

    public function queued(): self
    {
        return $this->state(fn (array $attrs): array => [
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'delivery_status' => DeliveryStatus::Failed->value,
            'failure_reason' => 'channel_returned_false',
            'sent_at' => null,
        ]);
    }

    public function ofType(string $type): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => $type,
        ]);
    }
}
