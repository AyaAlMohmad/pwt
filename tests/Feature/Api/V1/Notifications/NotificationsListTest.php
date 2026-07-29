<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Build a Notification row using a flat array state. Avoids the chained
 * factory state helpers on NotificationFactory because their `static fn`
 * closures cannot be bound at factory resolve time (an existing
 * production-code quirk). Each test passes the fields it needs to
 * customise; the rest fall back to the factory definition.
 *
 * @param  array<string, mixed>  $state
 */
function makeNotification(array $state): Notification
{
    /** @var Notification $n */
    $n = Notification::factory()->state($state)->create();

    return $n;
}

function makeUnread(int $userId, array $state = []): Notification
{
    return makeNotification(array_merge([
        'user_id' => $userId,
        'read_at' => null,
    ], $state));
}

function makeRead(int $userId, array $state = []): Notification
{
    return makeNotification(array_merge([
        'user_id' => $userId,
        'read_at' => now(),
    ], $state));
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/notifications — list inbox
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 1 (row 1), § 4.1, § 6.1.
| Owner-scoped paginated inbox with filters (unread_only, type, date_from,
| date_to). Sorted by created_at DESC, then id DESC. Default per_page 15,
| max 100. Response `meta.unread_count` carries the global unread total.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @return array{user: User, token: string}
 */
function makeNotificationsListCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

describe('GET /api/v1/notifications', function (): void {
    it('returns 200 with an empty data array, pagination meta, and unread_count=0 when no notifications exist', function (): void {
        ['token' => $token] = makeNotificationsListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data',
                'meta' => [
                    'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
                    'unread_count',
                ],
            ])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0)
            ->assertJsonPath('meta.unread_count', 0);
    });

    it('lists notifications sorted by created_at DESC with correct meta.unread_count', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        $older = makeUnread($user->id, ['created_at' => now()->subDays(3)]);
        $newer = makeUnread($user->id, ['created_at' => now()->subDay()]);
        // One already read should not bump unread_count.
        makeRead($user->id, ['created_at' => now()->subDays(2)]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.unread_count', 2);

        $ids = collect($response->json('data'))->pluck('id')->all();
        // First two should be the unread ones; the read one is in the middle by date.
        expect($ids[0])->toBe($newer->id);
        expect(in_array($older->id, $ids, true))->toBeTrue();
    });

    it('filters by unread_only=1 and excludes read rows', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        makeUnread($user->id);
        makeUnread($user->id);
        makeRead($user->id);
        makeRead($user->id);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?unread_only=1');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.unread_count', 2);

        foreach ($response->json('data') as $row) {
            expect($row['read_at'])->toBeNull();
        }
    });

    it('filters by unread_only=true (string) and excludes read rows', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        makeUnread($user->id);
        makeUnread($user->id);
        makeRead($user->id);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?unread_only=true');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.unread_count', 2);

        foreach ($response->json('data') as $row) {
            expect($row['read_at'])->toBeNull();
        }
    });

    it('filters by type=order.confirmed', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        makeNotification(['user_id' => $user->id, 'type' => 'order.confirmed']);
        makeNotification(['user_id' => $user->id, 'type' => 'order.created']);
        makeNotification(['user_id' => $user->id, 'type' => 'maintenance.created']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?type=order.confirmed');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.type', 'order.confirmed');
    });

    it('returns Arabic title + body when Accept-Language: ar is sent', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        makeNotification([
            'user_id' => $user->id,
            'type' => 'order.confirmed',
            'title' => ['en' => 'Your order is confirmed', 'ar' => 'تم تأكيد طلبك'],
            'body' => ['en' => 'EN body', 'ar' => 'نص بالعربية'],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'تم تأكيد طلبك')
            ->assertJsonPath('data.0.body', 'نص بالعربية');

        // English path returns English title.
        $en = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/notifications');

        $en->assertOk()->assertJsonPath('data.0.title', 'Your order is confirmed');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 422 ERR_VALIDATION when per_page exceeds 100', function (): void {
        ['token' => $token] = makeNotificationsListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?per_page=500');

        $response->assertStatus(422)->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when date_to is before date_from', function (): void {
        ['token' => $token] = makeNotificationsListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?date_from=2026-05-01&date_to=2026-04-01');

        $response->assertStatus(422)->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when type does not match the allowlist regex', function (): void {
        ['token' => $token] = makeNotificationsListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?type=Order.Confirmed');

        $response->assertStatus(422)->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('excludes other users\' notifications', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();
        $stranger = User::factory()->customer()->create();

        makeUnread($user->id);
        makeUnread($stranger->id);
        makeUnread($stranger->id);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.unread_count', 1);
    });

    it('exposes only the allowlisted fields (no channel, delivery_status, user_id, sent_at)', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        makeNotification(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications');

        $response->assertOk();
        $row = $response->json('data.0');
        expect($row)->toBeArray()
            ->and($row)->toHaveKeys(['id', 'public_id', 'type', 'title', 'body', 'data', 'action_url', 'read_at', 'created_at'])
            ->and($row)->not->toHaveKey('channel')
            ->and($row)->not->toHaveKey('delivery_status')
            ->and($row)->not->toHaveKey('user_id')
            ->and($row)->not->toHaveKey('sent_at')
            ->and($row)->not->toHaveKey('failure_reason');
    });

    it('paginates with per_page=2 and returns the right pagination meta', function (): void {
        ['user' => $user, 'token' => $token] = makeNotificationsListCustomer();

        Notification::factory()->count(5)->state(['user_id' => $user->id])->create();
        // (factory()->count(N)->state(array)->create() is safe — no chained
        // state methods involved.)

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/notifications?per_page=2&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 5)
            ->assertJsonPath('meta.pagination.last_page', 3);

        expect($response->json('data'))->toHaveCount(2);
    });
});
