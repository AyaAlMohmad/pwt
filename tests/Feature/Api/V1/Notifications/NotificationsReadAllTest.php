<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| POST /api/v1/notifications/read-all — bulk mark unread as read
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 1 (row 3), § 6.3.
| Returns 200 with `meta.updated_count` reflecting the number of rows
| updated. Scoped to the caller only — sibling customers' rows are not
| touched.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('POST /api/v1/notifications/read-all', function (): void {
    it('marks every unread row for the caller and reports updated_count', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        // 5 unread + 2 read = updated_count should be 5
        Notification::factory()->count(5)->state(['user_id' => $user->id, 'read_at' => null])->create();
        Notification::factory()->count(2)->state(['user_id' => $user->id, 'read_at' => now()])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.updated_count', 5);

        // Database state: every unread row is now read.
        $stillUnread = Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
        expect($stillUnread)->toBe(0);
        $total = Notification::query()->where('user_id', $user->id)->count();
        expect($total)->toBe(7);
    });

    it('returns 200 with updated_count=0 when there are zero unread rows', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        Notification::factory()->count(3)->state(['user_id' => $user->id, 'read_at' => now()])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('meta.updated_count', 0);
    });

    it('returns 200 with updated_count=0 when the user has no notifications at all', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('meta.updated_count', 0);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $response = $this->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('does not affect another user\'s unread notifications', function (): void {
        $caller = User::factory()->customer()->create();
        $callerToken = $caller->createToken('mobile', ['*'])->plainTextToken;
        $stranger = User::factory()->customer()->create();

        Notification::factory()->count(2)->state(['user_id' => $caller->id, 'read_at' => null])->create();
        $strangerNotifs = Notification::factory()->count(3)->state(['user_id' => $stranger->id, 'read_at' => null])->create();

        $response = $this->withHeader('Authorization', "Bearer $callerToken")
            ->postJson('/api/v1/notifications/read-all');

        $response->assertOk()->assertJsonPath('meta.updated_count', 2);

        // Stranger's notifications remain unread.
        foreach ($strangerNotifs as $n) {
            expect($n->fresh()->read_at)->toBeNull();
        }
    });
});
