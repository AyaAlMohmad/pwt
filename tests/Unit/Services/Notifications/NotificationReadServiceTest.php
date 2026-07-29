<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationReadService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| NotificationReadService unit tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 6.
| Three read-side methods: listForUser, unreadCount, markRead, markAllRead.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = new NotificationReadService;
});

describe('NotificationReadService::listForUser()', function (): void {
    it('paginates the caller\'s notifications sorted by created_at DESC', function (): void {
        $user = User::factory()->customer()->create();

        $older = Notification::factory()->state([
            'user_id' => $user->id,
            'created_at' => now()->subDays(3),
        ])->create();
        $newer = Notification::factory()->state([
            'user_id' => $user->id,
            'created_at' => now()->subHour(),
        ])->create();

        $paginator = $this->service->listForUser($user, [], 1, 15);

        expect($paginator->total())->toBe(2);
        $ids = collect($paginator->items())->pluck('id')->all();
        expect($ids[0])->toBe($newer->id);
        expect($ids[1])->toBe($older->id);
    });

    it('filters by unread_only', function (): void {
        $user = User::factory()->customer()->create();

        Notification::factory()->state(['user_id' => $user->id, 'read_at' => null])->create();
        Notification::factory()->state(['user_id' => $user->id, 'read_at' => now()])->create();

        $paginator = $this->service->listForUser($user, ['unread_only' => true], 1, 15);

        expect($paginator->total())->toBe(1);
    });

    it('filters by type', function (): void {
        $user = User::factory()->customer()->create();

        Notification::factory()->state(['user_id' => $user->id, 'type' => 'order.created'])->create();
        Notification::factory()->state(['user_id' => $user->id, 'type' => 'order.confirmed'])->create();

        $paginator = $this->service->listForUser($user, ['type' => 'order.confirmed'], 1, 15);

        expect($paginator->total())->toBe(1);
    });

    it('filters by date_from and date_to inclusive on created_at', function (): void {
        $user = User::factory()->customer()->create();

        Notification::factory()->state([
            'user_id' => $user->id,
            'created_at' => now()->subDays(2),
        ])->create();
        Notification::factory()->state([
            'user_id' => $user->id,
            'created_at' => now()->subDays(20),
        ])->create();

        $paginator = $this->service->listForUser($user, [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->addDay()->toDateString(),
        ], 1, 15);

        expect($paginator->total())->toBe(1);
    });

    it('excludes other users\' rows', function (): void {
        $user = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();

        Notification::factory()->state(['user_id' => $user->id])->create();
        Notification::factory()->count(3)->state(['user_id' => $stranger->id])->create();

        $paginator = $this->service->listForUser($user, [], 1, 15);

        expect($paginator->total())->toBe(1);
    });

    it('returns an empty paginator when the caller has zero rows', function (): void {
        $user = User::factory()->customer()->create();

        $paginator = $this->service->listForUser($user, [], 1, 15);

        expect($paginator->total())->toBe(0);
    });
});

describe('NotificationReadService::unreadCount()', function (): void {
    it('counts only unread rows for the caller', function (): void {
        $user = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();

        Notification::factory()->count(3)->state(['user_id' => $user->id, 'read_at' => null])->create();
        Notification::factory()->count(2)->state(['user_id' => $user->id, 'read_at' => now()])->create();
        Notification::factory()->count(4)->state(['user_id' => $stranger->id, 'read_at' => null])->create();

        expect($this->service->unreadCount($user))->toBe(3);
    });

    it('returns zero when no notifications exist for the caller', function (): void {
        $user = User::factory()->customer()->create();

        expect($this->service->unreadCount($user))->toBe(0);
    });
});

describe('NotificationReadService::markRead()', function (): void {
    it('sets read_at on an unread notification', function (): void {
        Carbon::setTestNow('2026-05-15 11:00:00');

        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id, 'read_at' => null])->create();

        $result = $this->service->markRead($notification);

        expect($result->read_at)->not->toBeNull();
        $fresh = $notification->fresh();
        expect($fresh->read_at)->not->toBeNull();

        Carbon::setTestNow();
    });

    it('is idempotent — preserves the original read_at on a re-mark', function (): void {
        $user = User::factory()->customer()->create();
        $original = Carbon::parse('2026-05-10 09:00:00');
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'read_at' => $original,
        ])->create();

        Carbon::setTestNow('2026-05-15 11:00:00');
        $result = $this->service->markRead($notification);
        Carbon::setTestNow();

        expect($result->read_at?->equalTo($original))->toBeTrue();
    });
});

describe('NotificationReadService::markAllRead()', function (): void {
    it('updates only the caller\'s unread rows and returns the count', function (): void {
        $user = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();

        Notification::factory()->count(4)->state(['user_id' => $user->id, 'read_at' => null])->create();
        Notification::factory()->count(1)->state(['user_id' => $user->id, 'read_at' => now()])->create();
        $strangerRows = Notification::factory()->count(2)
            ->state(['user_id' => $stranger->id, 'read_at' => null])->create();

        $updated = $this->service->markAllRead($user);
        expect($updated)->toBe(4);

        $callerUnread = Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
        expect($callerUnread)->toBe(0);

        foreach ($strangerRows as $r) {
            expect($r->fresh()->read_at)->toBeNull();
        }
    });

    it('returns 0 when there are no unread rows', function (): void {
        $user = User::factory()->customer()->create();

        Notification::factory()->count(2)->state(['user_id' => $user->id, 'read_at' => now()])->create();

        $updated = $this->service->markAllRead($user);
        expect($updated)->toBe(0);
    });
});
