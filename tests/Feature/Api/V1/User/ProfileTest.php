<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * Create a customer + access token and return both for use in feature tests.
 *
 * @return array{user: User, token: string}
 */
function makeAuthedCustomer(array $overrides = []): array
{
    $user = User::factory()->customer()->create(array_merge([
        'password' => Hash::make('Password1!'),
    ], $overrides));

    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/me
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/me', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no token is supplied', function (): void {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 200 with the allowlisted profile fields for the authenticated customer', function (): void {
        ['user' => $user, 'token' => $token] = makeAuthedCustomer([
            'name' => 'Ahmed Al Rashidi',
            'email' => 'ahmed@example.com',
            'locale' => 'en',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'phone',
                    'phone_country_code',
                    'locale',
                    'is_active',
                    'roles',
                    'created_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($user->id)
            ->and($response->json('data.email'))->toBe('ahmed@example.com')
            ->and($response->json('data.name'))->toBe('Ahmed Al Rashidi')
            ->and($response->json('data.roles'))->toBe(['customer'])
            ->and($response->json('data.is_active'))->toBeTrue();
    });

    it('does NOT expose password, remember_token, or email_verified_at', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me');

        $data = $response->json('data');
        expect($data)->not->toHaveKey('password')
            ->and($data)->not->toHaveKey('remember_token')
            ->and($data)->not->toHaveKey('email_verified_at')
            ->and($data)->not->toHaveKey('deleted_at');
    });
});

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/me
|--------------------------------------------------------------------------
*/

describe('PATCH /api/v1/me', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when unauthenticated', function (): void {
        $response = $this->patchJson('/api/v1/me', ['name' => 'New Name']);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('updates name and locale and returns the refreshed profile', function (): void {
        ['user' => $user, 'token' => $token] = makeAuthedCustomer([
            'name' => 'Old Name',
            'locale' => 'en',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me', [
                'name' => 'Updated Name',
                'locale' => 'ar',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.locale', 'ar');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'locale' => 'ar',
        ]);
    });

    it('updates the email with the correct current_password and rejects duplicates', function (): void {
        ['user' => $user, 'token' => $token] = makeAuthedCustomer([
            'email' => 'alice@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me', [
                'email' => 'alice.new@example.com',
                'current_password' => 'Password1!',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.email', 'alice.new@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'alice.new@example.com',
        ]);
    });

    it('returns 422 ERR_VALIDATION when changing email without supplying current_password', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me', [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when the email is already used by another active user', function (): void {
        User::factory()->customer()->create(['email' => 'taken@example.com']);
        ['token' => $token] = makeAuthedCustomer([
            'email' => 'me@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me', [
                'email' => 'taken@example.com',
                'current_password' => 'Password1!',
            ]);

        // Uniqueness fires at the validator → 422 ERR_VALIDATION, with the
        // localized "email taken" message attached at errors.email.
        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.email.0', __('auth.email_taken'));
    });

    it('returns 409 ERR_PHONE_CHANGE_NOT_SUPPORTED when the phone differs from the stored value', function (): void {
        ['token' => $token] = makeAuthedCustomer([
            'phone_country_code' => '+971',
            'phone' => '501234567',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me', [
                'phone' => '+971509999999',
                'current_password' => 'Password1!',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_PHONE_CHANGE_NOT_SUPPORTED');
    });

    it('returns Arabic validation messages when Accept-Language: ar', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->patchJson('/api/v1/me', [
                'email' => 'not-a-valid-email',
                'current_password' => 'Password1!',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');

        // The Arabic validation namespace should resolve — the message is
        // not English. Concretely we check that the response doesn't echo
        // the English "must be a valid email" string.
        $message = (string) $response->json('errors.email.0');
        expect($message)->not->toContain('must be a valid email');
    });
});

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/me
|--------------------------------------------------------------------------
*/

describe('DELETE /api/v1/me', function (): void {
    it('returns 401 when unauthenticated', function (): void {
        $response = $this->deleteJson('/api/v1/me', [
            'password' => 'Password1!',
            'confirm' => 'DELETE',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 422 ERR_VALIDATION when confirm literal is missing', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/me', [
                'password' => 'Password1!',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when password is missing', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/me', [
                'confirm' => 'DELETE',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 ERR_VALIDATION when the password is wrong', function (): void {
        ['token' => $token] = makeAuthedCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/me', [
                'password' => 'WrongPassword',
                'confirm' => 'DELETE',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('soft-deletes the user, revokes ALL tokens, and marks the row inactive', function (): void {
        ['user' => $user, 'token' => $token] = makeAuthedCustomer();
        $user->createToken('other-device');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/me', [
                'password' => 'Password1!',
                'confirm' => 'DELETE',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        // User is soft-deleted + inactive.
        $user->refresh();
        expect($user->deleted_at)->not->toBeNull()
            ->and($user->is_active)->toBeFalse();

        // Both the auth token and any other token rows are gone — proves
        // the "revoke all tokens" contract at the DB layer (Laravel's
        // test-time Auth guard cache prevents asserting via a follow-up
        // /me call within the same test method).
        $this->assertDatabaseCount('personal_access_tokens', 0);
        expect($user->tokens()->count())->toBe(0);
    });
});
