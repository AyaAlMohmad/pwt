<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

describe('POST /api/v1/auth/logout', function (): void {
    it('returns 200 and deletes the current personal_access_tokens row', function (): void {
        $user = User::factory()->customer()->create();
        $plain = $user->createToken('mobile')->plainTextToken;
        $tokenId = (int) explode('|', $plain, 2)[0];

        $response = $this->withHeader('Authorization', "Bearer $plain")
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);

        expect($response->json('data'))->toBeNull();

        // The PAT row is gone — in production, this is what makes the next
        // Sanctum lookup fail and return 401. Asserting at the DB layer
        // sidesteps Laravel's test-time Auth guard caching, which keeps the
        // resolved user attached to the in-memory Auth manager across calls
        // within a single test method (a known testing-only quirk; see
        // Illuminate\Auth\RequestGuard::$user).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        expect($user->tokens()->count())->toBe(0);
    });

    it('returns 401 ERR_UNAUTHENTICATED when no token is supplied', function (): void {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('revokes only the current token, leaving other tokens on the user', function (): void {
        $user = User::factory()->customer()->create();
        $plainA = $user->createToken('device-a')->plainTextToken;
        $plainB = $user->createToken('device-b')->plainTextToken;
        $idA = (int) explode('|', $plainA, 2)[0];
        $idB = (int) explode('|', $plainB, 2)[0];

        $this->withHeader('Authorization', "Bearer $plainA")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $idA]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $idB]);
        expect($user->tokens()->count())->toBe(1);
    });
});
