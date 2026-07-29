<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
});

/**
 * @return array{user: User, token: string}
 */
function makeAddressDeleteCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/me/addresses/{id}
|--------------------------------------------------------------------------
*/

describe('DELETE /api/v1/me/addresses/{id}', function (): void {
    it('soft-deletes a non-default address and leaves the existing default in place', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressDeleteCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $default = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
        ]);
        $victim = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$victim->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'deleted_at', 'promoted_default_id'],
            ])
            ->assertJsonPath('data.id', $victim->id)
            ->assertJsonPath('data.promoted_default_id', null);

        // Soft-deleted: row still exists with deleted_at set.
        $this->assertSoftDeleted('addresses', ['id' => $victim->id]);

        // Existing default unchanged.
        $default->refresh();
        expect($default->is_default)->toBeTrue();
    });

    it('auto-promotes the next-most-recent surviving address when the default is deleted', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressDeleteCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $default = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
            'created_at' => now()->subDays(5),
            'label' => 'Default being deleted',
        ]);
        $older = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => false,
            'created_at' => now()->subDays(3),
            'label' => 'Older sibling',
        ]);
        $newer = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => false,
            'created_at' => now()->subDay(),
            'label' => 'Newer sibling',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$default->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $default->id)
            ->assertJsonPath('data.promoted_default_id', $newer->id);

        $this->assertSoftDeleted('addresses', ['id' => $default->id]);

        // $newer (most recently created) is now the default.
        $newer->refresh();
        $older->refresh();
        expect($newer->is_default)->toBeTrue();
        expect($older->is_default)->toBeFalse();

        // Exactly one default exists.
        expect(Address::query()->where('user_id', $user->id)->where('is_default', true)->count())
            ->toBe(1);
    });

    it('returns 200 with promoted_default_id=null when the last address (also default) is deleted', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressDeleteCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $only = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$only->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $only->id)
            ->assertJsonPath('data.promoted_default_id', null);

        $this->assertSoftDeleted('addresses', ['id' => $only->id]);

        // No active addresses left.
        expect(Address::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    it('returns 403 and does not soft-delete the row when the caller does not own it', function (): void {
        ['token' => $token] = makeAddressDeleteCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false);

        // Address still active (not soft-deleted).
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'deleted_at' => null,
        ]);
    });

    it('returns code=ERR_FORBIDDEN on DELETE ownership failure (architecture doc § 5.5)', function (): void {
        ['token' => $token] = makeAddressDeleteCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the address id does not exist', function (): void {
        ['token' => $token] = makeAddressDeleteCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson('/api/v1/me/addresses/999999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED when unauthenticated', function (): void {
        ['user' => $user] = makeAddressDeleteCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->create();

        $response = $this->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 404 on the second DELETE call (soft-deleted rows are scoped out of route-model binding)', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressDeleteCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->create();

        $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}")
            ->assertOk();

        $second = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $second->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});
