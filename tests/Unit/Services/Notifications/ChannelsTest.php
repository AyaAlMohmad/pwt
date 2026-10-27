<?php

declare(strict_types=1);

use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Notifications\Channels\Drivers\EmailChannel;
use App\Services\Notifications\Channels\Drivers\InAppChannel;
use App\Services\Notifications\Channels\Drivers\PushChannel;
use App\Services\Notifications\Channels\Drivers\SmsChannel;
use App\Services\Notifications\Channels\Drivers\WhatsAppChannel;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use App\Services\Notifications\NotificationChannelRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Channel-driver unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 5.3.
| Each channel returns true on success / false on soft-fail. Email queues
| GenericNotificationMail. SMS delegates to the StubSmsDriver. WhatsApp +
| Push are logging stubs.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    StubSmsDriver::flush();
});

describe('InAppChannel', function (): void {
    it('returns true (the persisted row IS the delivery)', function (): void {
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id])->create();

        $channel = new InAppChannel;

        expect($channel->send($notification))->toBeTrue();
    });
});

describe('EmailChannel', function (): void {
    it('queues GenericNotificationMail to the user\'s email and returns true', function (): void {
        Mail::fake();
        $user = User::factory()->customer()->state(['email' => 'cust@example.com'])->create();
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Email->value,
            'title' => ['en' => 'Hi there', 'ar' => 'مرحبا'],
        ])->create();

        $channel = new EmailChannel;

        expect($channel->send($notification))->toBeTrue();

        Mail::assertQueued(GenericNotificationMail::class, function (GenericNotificationMail $m) use ($user, $notification): bool {
            return $m->hasTo($user->email)
                && $m->notification->id === $notification->id;
        });
    });

    it('returns false and logs a warning when the user has no email', function (): void {
        Mail::fake();
        Log::spy();
        $user = User::factory()->customer()->state(['email' => null])->create();
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Email->value,
        ])->create();

        $channel = new EmailChannel;

        expect($channel->send($notification))->toBeFalse();
        Mail::assertNothingQueued();
        Log::shouldHaveReceived('warning')->atLeast()->once();
    });

    it('returns false when the underlying user record is unresolvable', function (): void {
        Mail::fake();
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Email->value,
        ])->create();

        // Simulate the User::find() lookup returning null by stashing a
        // fake notification instance whose user_id is unresolvable. We
        // can't violate the FK on insert, so we mutate the in-memory
        // model only — EmailChannel re-queries via User::find().
        $notification->setRawAttributes(array_merge($notification->getAttributes(), ['user_id' => 999999]));

        $channel = new EmailChannel;

        expect($channel->send($notification))->toBeFalse();
        Mail::assertNothingQueued();
    });
});

describe('SmsChannel', function (): void {
    it('delegates to the SmsDriver with the user\'s phone and the rendered body', function (): void {
        $user = User::factory()->customer()->state([
            'phone' => '501234567',
            'phone_country_code' => '+971',
            'locale' => 'en',
        ])->create();

        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Sms->value,
            'title' => ['en' => 'Title', 'ar' => 'العنوان'],
            'body' => ['en' => 'Your order is confirmed.', 'ar' => 'تم تأكيد طلبك.'],
        ])->create();

        $channel = app(SmsChannel::class);

        expect($channel->send($notification))->toBeTrue();

        $last = StubSmsDriver::lastDispatched();
        expect($last)->not->toBeNull()
            ->and($last['to'])->toBe('+971501234567')
            ->and($last['message'])->toBe('Your order is confirmed.');
        expect($last['context']['notification_id'] ?? null)->toBe($notification->id);
    });

    it('returns false and skips when the user has no phone', function (): void {
        Log::spy();
        $user = User::factory()->customer()->state([
            'phone' => null,
            'phone_country_code' => '+971',
        ])->create();
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Sms->value,
        ])->create();

        $channel = app(SmsChannel::class);

        expect($channel->send($notification))->toBeFalse();
        Log::shouldHaveReceived('warning')->atLeast()->once();
    });
});

describe('WhatsAppChannel', function (): void {
    it('returns true and logs the stub dispatch', function (): void {
        Log::spy();
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id])->create();

        $channel = new WhatsAppChannel;

        expect($channel->send($notification))->toBeTrue();
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notifications.whatsapp.stub_send';
            });
    });
});

describe('PushChannel', function (): void {
    it('returns true when the user has no active device tokens', function (): void {
        Log::spy();
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id])->create();

        $channel = new PushChannel;

        expect($channel->send($notification))->toBeTrue();
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notifications.push.no_tokens';
            });
    });

    it('returns false when Firebase credentials are missing', function (): void {
        Log::spy();
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id])->create();

        UserDeviceToken::create([
            'user_id' => $user->id,
            'device_token' => 'fake_token',
            'platform' => 'android',
            'is_active' => true,
        ]);

        config()->set('services.fcm.credentials', '');

        $channel = new PushChannel;

        expect($channel->send($notification))->toBeFalse();
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notifications.push.missing_credentials';
            });
    });
});

describe('NotificationChannelRegistry', function (): void {
    it('resolves every enum value to a driver implementation', function (): void {
        /** @var NotificationChannelRegistry $registry */
        $registry = app(NotificationChannelRegistry::class);

        expect($registry->for(NotificationChannelEnum::InApp))->toBeInstanceOf(InAppChannel::class);
        expect($registry->for(NotificationChannelEnum::Email))->toBeInstanceOf(EmailChannel::class);
        expect($registry->for(NotificationChannelEnum::Sms))->toBeInstanceOf(SmsChannel::class);
        expect($registry->for(NotificationChannelEnum::WhatsApp))->toBeInstanceOf(WhatsAppChannel::class);
        expect($registry->for(NotificationChannelEnum::Push))->toBeInstanceOf(PushChannel::class);
    });
});
