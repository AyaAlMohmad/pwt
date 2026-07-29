<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Services\Notifications\Channels\Drivers\EmailChannel;
use App\Services\Notifications\Channels\Drivers\InAppChannel;
use App\Services\Notifications\Channels\Drivers\PushChannel;
use App\Services\Notifications\Channels\Drivers\SmsChannel;
use App\Services\Notifications\Channels\Drivers\WhatsAppChannel;
use App\Services\Notifications\Channels\NotificationChannel as ChannelContract;
use RuntimeException;

/**
 * Resolves a {@see NotificationChannelEnum} value to its driver
 * implementation. Constructor-injected so PHPStan can see the full
 * dependency graph and the unit tests can swap stubs via the container.
 */
final class NotificationChannelRegistry
{
    /** @var array<string, ChannelContract> */
    private array $map;

    public function __construct(
        InAppChannel $inApp,
        EmailChannel $email,
        SmsChannel $sms,
        WhatsAppChannel $whatsapp,
        PushChannel $push,
    ) {
        $this->map = [
            NotificationChannelEnum::InApp->value => $inApp,
            NotificationChannelEnum::Email->value => $email,
            NotificationChannelEnum::Sms->value => $sms,
            NotificationChannelEnum::WhatsApp->value => $whatsapp,
            NotificationChannelEnum::Push->value => $push,
        ];
    }

    public function for(NotificationChannelEnum $channel): ChannelContract
    {
        return $this->map[$channel->value]
            ?? throw new RuntimeException("Unknown notification channel: {$channel->value}");
    }
}
