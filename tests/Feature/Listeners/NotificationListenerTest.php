<?php

declare(strict_types=1);

use App\Events\Auth\OtpLoginIssued;
use App\Events\Auth\OtpRegisterIssued;
use App\Events\Auth\PasswordResetEmail;
use App\Events\Auth\PasswordResetPhone;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Events\Orders\OrderConfirmed;
use App\Events\Orders\OrderCreated;
use App\Events\Payments\PaymentCaptured;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| NotificationListener integration tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 3, § 5.1, § 5.5.
| The single fan-out listener turns a domain event into one persisted
| Notification row per channel (and queues a SendNotificationJob). The
| four `auth.*` events are deliberate NO-OPS.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(NotificationTemplatesSeeder::class);

    Mail::fake();
    config(['notifications.duplicate_window_seconds' => 0]);
});

describe('NotificationListener fan-out', function (): void {
    it('persists in_app + email rows when OrderCreated fires', function (): void {
        $user = User::factory()->customer()->create();

        event(new OrderCreated(orderId: 4242, userId: (int) $user->id));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'order.created')
            ->get();

        // Per config('notifications.channels_per_type.order.created') = ['in_app', 'email']
        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['email', 'in_app']);

        foreach ($rows as $row) {
            expect($row->title)->not->toBe('');
        }
    });

    it('persists in_app + sms + email rows when OrderConfirmed fires', function (): void {
        $user = User::factory()->customer()->create();

        event(new OrderConfirmed(orderId: 99, userId: (int) $user->id));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'order.confirmed')
            ->get();

        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['email', 'in_app', 'sms']);
    });

    it('persists an in_app row when MaintenanceRequestCreated fires', function (): void {
        $user = User::factory()->customer()->create();

        event(new MaintenanceRequestCreated(
            requestId: 17,
            userId: (int) $user->id,
            machineId: 5,
        ));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'maintenance.created')
            ->get();

        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['in_app']);
    });

    it('persists in_app + email rows when PaymentCaptured fires', function (): void {
        $user = User::factory()->customer()->create();

        event(new PaymentCaptured(paymentId: 1, orderId: 1, userId: (int) $user->id));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'payment.captured')
            ->get();

        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['email', 'in_app']);
    });

    it('no-ops for OtpRegisterIssued, OtpLoginIssued, PasswordResetEmail, PasswordResetPhone', function (): void {
        $user = User::factory()->customer()->create();

        event(new OtpRegisterIssued(phone: '+971501234567', userId: (int) $user->id));
        event(new OtpLoginIssued(phone: '+971501234567', userId: (int) $user->id));
        event(new PasswordResetEmail(userId: (int) $user->id, email: 'a@b.test'));
        event(new PasswordResetPhone(userId: (int) $user->id, phone: '+971501234567'));

        // None of the auth events should persist any Notification rows.
        expect(Notification::query()->count())->toBe(0);
    });

    it('suppresses duplicate dispatch within the 30-second window keyed on (user, type, order_id)', function (): void {
        config(['notifications.duplicate_window_seconds' => 30]);

        $user = User::factory()->customer()->create();

        event(new OrderCreated(orderId: 4242, userId: (int) $user->id));
        $firstCount = Notification::query()->where('user_id', $user->id)->count();

        // Same event again — should be suppressed by the dedupe window.
        event(new OrderCreated(orderId: 4242, userId: (int) $user->id));
        $secondCount = Notification::query()->where('user_id', $user->id)->count();

        expect($secondCount)->toBe($firstCount);

        // A different order_id is NOT suppressed.
        event(new OrderCreated(orderId: 9999, userId: (int) $user->id));
        $thirdCount = Notification::query()->where('user_id', $user->id)->count();
        expect($thirdCount)->toBeGreaterThan($secondCount);
    });

    it('cross-module: OrderConfirmed fired through the listener produces a notification visible in the customer inbox', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        event(new OrderConfirmed(orderId: 4242, userId: (int) $user->id));

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?type=order.confirmed');

        $response->assertOk();
        // The in_app row should show up in the inbox (alongside the sms + email rows).
        expect($response->json('meta.pagination.total'))->toBeGreaterThanOrEqual(1);
    });

    it('the dispatcher renders the template title via TemplateRenderer (non-empty title persisted)', function (): void {
        $user = User::factory()->customer()->create();

        event(new OrderConfirmed(orderId: 555, userId: (int) $user->id));

        $row = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'order.confirmed')
            ->where('channel', 'in_app')
            ->first();

        expect($row)->not->toBeNull();
        $en = $row->getTranslation('title', 'en');
        $ar = $row->getTranslation('title', 'ar');
        expect($en)->not->toBe('')->and($ar)->not->toBe('');
    });

    it('dispatchFor() returns an empty array when the event payload has no resolvable user_id', function (): void {
        // OtpRegisterIssued with userId=null — listener short-circuits and
        // dispatchFor() would itself return [] thanks to the user_id check.
        /** @var NotificationDispatcher $dispatcher */
        $dispatcher = app(NotificationDispatcher::class);

        $event = new OtpRegisterIssued(phone: '+971501234567', userId: null);
        $persisted = $dispatcher->dispatchFor($event);

        expect($persisted)->toBe([]);
        expect(Notification::query()->count())->toBe(0);
    });
});
