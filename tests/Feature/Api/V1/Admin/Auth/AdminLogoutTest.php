<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('POST /api/v1/admin/auth/logout', function (): void {
    it('returns 200 and deletes the current personal_access_tokens row', function (): void {
        $admin = User::factory()->admin(UserRole::SuperAdmin->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;
        $tokenId = (int) explode('|', $plain, 2)[0];

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->postJson('/api/v1/admin/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);

        expect($response->json('data'))->toBeNull();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        expect($admin->tokens()->count())->toBe(0);
    });

    it('revokes ONLY the current token, leaving other tokens on the same admin intact', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $plainA = $admin->createToken('admin', ['admin'])->plainTextToken;
        $plainB = $admin->createToken('admin', ['admin'])->plainTextToken;
        $idA = (int) explode('|', $plainA, 2)[0];
        $idB = (int) explode('|', $plainB, 2)[0];

        $this->withHeader('Authorization', "Bearer $plainA")
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $idA]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $idB]);
        expect($admin->tokens()->count())->toBe(1);
    });

    it('does NOT touch tokens belonging to a different admin user', function (): void {
        $alice = User::factory()->admin(UserRole::Operations->value)->create();
        $bob = User::factory()->admin(UserRole::Operations->value)->create();

        $aliceToken = $alice->createToken('admin', ['admin'])->plainTextToken;
        $bobToken = $bob->createToken('admin', ['admin'])->plainTextToken;
        $bobId = (int) explode('|', $bobToken, 2)[0];

        $this->withHeader('Authorization', "Bearer $aliceToken")
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $bobId]);
        expect($bob->tokens()->count())->toBe(1);
    });

    it('writes an activity_log entry on successful admin logout', function (): void {
        $admin = User::factory()->admin(UserRole::Operations->value)->create();
        $plain = $admin->createToken('admin', ['admin'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $plain")
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        /** @var Activity|null $entry */
        $entry = Activity::query()
            ->where('log_name', 'auth')
            ->where('event', 'admin.logout')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->description)->toBe('Admin logged out')
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->subject_id)->toBe($admin->id);
    });

    it('returns 401 ERR_UNAUTHENTICATED when no Bearer token is supplied', function (): void {
        $response = $this->postJson('/api/v1/admin/auth/logout');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 403 ERR_FORBIDDEN when a customer token is used', function (): void {
        $customer = User::factory()->customer()->create();
        $plain = $customer->createToken('mobile')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->postJson('/api/v1/admin/auth/logout');

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
