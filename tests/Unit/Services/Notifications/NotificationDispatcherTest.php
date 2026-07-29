<?php

declare(strict_types=1);

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel as NotificationChannelEnum;
use App\Events\Auth\OtpRegisterIssued;
use App\Events\Orders\OrderConfirmed;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| NotificationDispatcher unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 5.2, § 5.5.
| Persists one row per channel, dispatches SendNotificationJob per row,
| respects the duplicate-suppression window, and supports a custom
| channel override (used by tests + future Phase 22 admin previewing).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(NotificationTemplatesSeeder::class);

    $this->dispatcher = app(NotificationDispatcher::class);

    config(['notifications.duplicate_window_seconds' => 0]);
});

describe('NotificationDispatcher::dispatch()', function (): void {
    it('persists one Notification row per channel for the given type', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 99, 'order_public_id' => 'pub-99'],
        );

        // order.confirmed → ['in_app', 'sms', 'email']
        expect($rows)->toHaveCount(3);

        $channels = collect($rows)->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all();
        expect($channels)->toBe(['email', 'in_app', 'sms']);
    });

    it('queues exactly one SendNotificationJob per persisted row', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 99],
        );

        Queue::assertPushed(SendNotificationJob::class, count($rows));
        foreach ($rows as $row) {
            Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($row): bool {
                return $job->notificationId === (int) $row->id;
            });
        }
    });

    it('respects the override channels argument and ignores config when one is passed', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 99],
            overrideChannels: [NotificationChannelEnum::InApp],
        );

        expect($rows)->toHaveCount(1);
        expect($rows[0]->channel)->toBe(NotificationChannelEnum::InApp);
    });

    it('returns an empty array when no channels are configured for the type', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'no.such.type',
            recipient: $user,
            data: [],
        );

        expect($rows)->toBe([]);
        Queue::assertNothingPushed();
    });

    it('logs a warning and skips channels with no matching active template', function (): void {
        Queue::fake();
        Log::spy();
        $user = User::factory()->customer()->create();

        // Disable all templates for order.confirmed so the dispatcher
        // must skip every channel.
        NotificationTemplate::query()
            ->where('key', 'order.confirmed')
            ->update(['is_active' => false]);

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 99],
        );

        expect($rows)->toBe([]);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    });

    it('persists rows with queued delivery_status and a non-empty title', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 99, 'order_public_id' => 'pub-99'],
        );

        foreach ($rows as $row) {
            expect($row->delivery_status)->toBe(DeliveryStatus::Queued);
            expect($row->user_id)->toBe($user->id);
            // Title is rendered to a non-empty string in both locales.
            expect((string) $row->getTranslation('title', 'en'))->not->toBe('');
            expect((string) $row->getTranslation('title', 'ar'))->not->toBe('');
        }
    });

    it('suppresses a duplicate dispatch within the configured window keyed on (user, type, order_id)', function (): void {
        Queue::fake();
        config(['notifications.duplicate_window_seconds' => 60]);
        $user = User::factory()->customer()->create();

        $first = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 1234],
        );
        $second = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 1234],
        );

        expect($first)->not->toBe([]);
        expect($second)->toBe([]);
    });

    it('does not suppress when the business id differs', function (): void {
        Queue::fake();
        config(['notifications.duplicate_window_seconds' => 60]);
        $user = User::factory()->customer()->create();

        $first = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 1],
        );
        $second = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: ['order_id' => 2],
        );

        expect($first)->not->toBe([]);
        expect($second)->not->toBe([]);
    });

    it('strips closures and Eloquent models from the persisted data blob', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $rows = $this->dispatcher->dispatch(
            type: 'order.confirmed',
            recipient: $user,
            data: [
                'order_id' => 99,
                'closure' => static fn (): int => 1,    // dropped
                'model' => $user,                       // dropped
                'note' => 'safe scalar',
                'nested' => ['ok' => true, 'cb' => static fn (): int => 1],
            ],
        );

        $row = $rows[0];
        expect($row->data)->toBeArray()
            ->and($row->data)->toHaveKey('order_id')
            ->and($row->data)->toHaveKey('note')
            ->and($row->data)->not->toHaveKey('closure')
            ->and($row->data)->not->toHaveKey('model');
        expect($row->data['nested'])->toBeArray()
            ->and($row->data['nested'])->toHaveKey('ok')
            ->and($row->data['nested'])->not->toHaveKey('cb');
    });
});

describe('NotificationDispatcher::dispatchFor()', function (): void {
    it('returns [] without persisting when the event payload has no user_id', function (): void {
        Queue::fake();

        $event = new OtpRegisterIssued(phone: '+971501234567', userId: null);
        $rows = $this->dispatcher->dispatchFor($event);

        expect($rows)->toBe([]);
        expect(Notification::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('fans out from a domain event when the recipient exists', function (): void {
        Queue::fake();
        $user = User::factory()->customer()->create();

        $event = new OrderConfirmed(orderId: 4242, userId: (int) $user->id);
        $rows = $this->dispatcher->dispatchFor($event);

        expect($rows)->toHaveCount(3); // in_app + sms + email
        Queue::assertPushed(SendNotificationJob::class, 3);
    });

    it('warns and returns [] when the recipient user has been deleted', function (): void {
        Queue::fake();
        Log::spy();

        $event = new OrderConfirmed(orderId: 1, userId: 999999);
        $rows = $this->dispatcher->dispatchFor($event);

        expect($rows)->toBe([]);
        Log::shouldHaveReceived('warning')->atLeast()->once();
    });
});
