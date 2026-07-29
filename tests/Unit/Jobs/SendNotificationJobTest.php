<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\Channels\NotificationChannel as ChannelContract;
use App\Services\Notifications\NotificationChannelRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| SendNotificationJob unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 5.2.1.
| Resolves the channel driver, calls send(), updates delivery_status and
| sent_at on success, or delivery_status=failed + failure_reason on
| soft-fail or exception. Idempotent on duplicate dispatch.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Build a registry that returns a stubbed channel for the given enum.
 */
function registryReturning(NotificationChannelEnum $channel, ChannelContract $driver): NotificationChannelRegistry
{
    /** @var NotificationChannelRegistry $registry */
    $registry = app(NotificationChannelRegistry::class);

    // Replace the underlying map via reflection so we don't have to
    // rebuild every other channel.
    $ref = new ReflectionClass($registry);
    $prop = $ref->getProperty('map');
    $prop->setAccessible(true);
    $map = $prop->getValue($registry);
    $map[$channel->value] = $driver;
    $prop->setValue($registry, $map);

    return $registry;
}

describe('SendNotificationJob::handle()', function (): void {
    it('marks an in_app row as delivered + sent_at on success', function (): void {
        $user = User::factory()->customer()->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::InApp->value,
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ])->create();

        $job = new SendNotificationJob((int) $row->id);
        $job->handle(app(NotificationChannelRegistry::class));

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Delivered);
        expect($fresh->sent_at)->not->toBeNull();
        expect($fresh->failure_reason)->toBeNull();
    });

    it('marks a non-in_app row as sent (not delivered) on success', function (): void {
        $user = User::factory()->customer()->state(['email' => 'x@y.test'])->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::Email->value,
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ])->create();

        Mail::fake();

        $job = new SendNotificationJob((int) $row->id);
        $job->handle(app(NotificationChannelRegistry::class));

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Sent);
        expect($fresh->sent_at)->not->toBeNull();
    });

    it('marks a row as failed when the channel driver returns false', function (): void {
        $user = User::factory()->customer()->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::InApp->value,
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ])->create();

        $falsy = new class implements ChannelContract
        {
            public function send(Notification $notification): bool
            {
                return false;
            }
        };
        $registry = registryReturning(NotificationChannelEnum::InApp, $falsy);

        $job = new SendNotificationJob((int) $row->id);
        $job->handle($registry);

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Failed);
        expect($fresh->failure_reason)->toBe('channel_returned_false');
        expect($fresh->sent_at)->toBeNull();
    });

    it('marks a row failed and re-throws when the channel throws', function (): void {
        $user = User::factory()->customer()->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::InApp->value,
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ])->create();

        $thrower = new class implements ChannelContract
        {
            public function send(Notification $notification): bool
            {
                throw new RuntimeException('gateway exploded');
            }
        };
        $registry = registryReturning(NotificationChannelEnum::InApp, $thrower);

        $job = new SendNotificationJob((int) $row->id);

        expect(fn () => $job->handle($registry))
            ->toThrow(RuntimeException::class, 'gateway exploded');

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Failed);
        expect($fresh->failure_reason)->toContain('gateway exploded');
    });

    it('is idempotent — a duplicate dispatch on an already-delivered row is a no-op', function (): void {
        $user = User::factory()->customer()->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'channel' => NotificationChannelEnum::InApp->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'sent_at' => now()->subHour(),
        ])->create();

        $originalSentAt = $row->sent_at;

        $job = new SendNotificationJob((int) $row->id);
        $job->handle(app(NotificationChannelRegistry::class));

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Delivered);
        expect($fresh->sent_at?->equalTo($originalSentAt))->toBeTrue();
    });

    it('warns and returns when the notification row is missing', function (): void {
        Log::spy();

        $job = new SendNotificationJob(999999);
        $job->handle(app(NotificationChannelRegistry::class));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message): bool {
                return $message === 'notifications.job.row_missing';
            });
    });
});

describe('SendNotificationJob::failed()', function (): void {
    it('marks the row failed with the truncated exception message on terminal failure', function (): void {
        $user = User::factory()->customer()->create();
        $row = Notification::factory()->state([
            'user_id' => $user->id,
            'delivery_status' => DeliveryStatus::Queued->value,
            'sent_at' => null,
        ])->create();

        $job = new SendNotificationJob((int) $row->id);
        $job->failed(new RuntimeException('final failure cause'));

        $fresh = $row->fresh();
        expect($fresh->delivery_status)->toBe(DeliveryStatus::Failed);
        expect($fresh->failure_reason)->toContain('final failure cause');
    });
});
