<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/notifications/{id}/read — mark single
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/notifications.md § 1 (row 2), § 6.2.
| Owner-scoped via NotificationPolicy::update → 403 on foreign id
| (per CLAUDE.md § 7.3 — 403 not 404). Idempotent: re-marking preserves
| read_at and still returns 200.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('PATCH /api/v1/notifications/{id}/read', function (): void {
    it('marks an unread notification as read and returns 200 with the updated row', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;
        $notification = Notification::factory()->state(['user_id' => $user->id, 'read_at' => null])->create();

        expect($notification->read_at)->toBeNull();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $notification->id);
        expect($response->json('data.read_at'))->not->toBeNull();

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
        ]);
        $fresh = $notification->fresh();
        expect($fresh->read_at)->not->toBeNull();
    });

    it('is idempotent — re-marking an already-read row preserves the original read_at and still returns 200', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        $readAt = now()->subDays(1)->startOfHour();
        $notification = Notification::factory()->state([
            'user_id' => $user->id,
            'read_at' => $readAt,
        ])->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk();

        // The persisted read_at is unchanged.
        $fresh = $notification->fresh();
        expect($fresh->read_at?->equalTo($readAt))->toBeTrue();
    });

    it('returns 404 ERR_NOT_FOUND for a non-existent id', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/notifications/9999999/read');

        $response->assertStatus(404)->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 403 ERR_FORBIDDEN when the notification belongs to another user', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $strangerToken = $stranger->createToken('mobile', ['*'])->plainTextToken;

        $notification = Notification::factory()->state(['user_id' => $owner->id, 'read_at' => null])->create();

        $response = $this->withHeader('Authorization', "Bearer $strangerToken")
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(403)->assertJsonPath('code', 'ERR_FORBIDDEN');

        // No state change on the foreign row.
        $fresh = $notification->fresh();
        expect($fresh->read_at)->toBeNull();
    });

    it('returns 401 ERR_UNAUTHENTICATED without a Bearer token', function (): void {
        $user = User::factory()->customer()->create();
        $notification = Notification::factory()->state(['user_id' => $user->id, 'read_at' => null])->create();

        $response = $this->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertStatus(401)->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 404 for a non-numeric id (route does not match the {id} number constraint)', function (): void {
        $user = User::factory()->customer()->create();
        $token = $user->createToken('mobile', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/notifications/not-a-number/read');

        $response->assertStatus(404);
    });
});
