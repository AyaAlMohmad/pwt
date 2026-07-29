<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
final class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'order.confirmed',
            'channel' => NotificationChannel::InApp->value,
            'subject' => [
                'en' => 'Your order is confirmed',
                'ar' => 'تم تأكيد طلبك',
            ],
            'body' => [
                'en' => 'Order #{{order.public_id}} has been confirmed.',
                'ar' => 'تم تأكيد الطلب رقم {{order.public_id}}.',
            ],
            'placeholders' => ['order.public_id', 'order.id', 'user.name'],
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(static fn (array $attrs): array => [
            'is_active' => false,
        ]);
    }

    public function forChannel(NotificationChannel $channel): self
    {
        return $this->state(static fn (array $attrs): array => [
            'channel' => $channel->value,
        ]);
    }

    public function forKey(string $key): self
    {
        return $this->state(static fn (array $attrs): array => [
            'key' => $key,
        ]);
    }
}
