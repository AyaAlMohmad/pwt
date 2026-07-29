<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Region;
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
function makeAddressUpdateCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/me/addresses/{id}
|--------------------------------------------------------------------------
*/

describe('PATCH /api/v1/me/addresses/{id}', function (): void {
    it('returns 200 and updates only the supplied fields on a partial update', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressUpdateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->create([
            'label' => 'Old label',
            'city' => 'Dubai',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$address->id}", [
                'label' => 'New label',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'label', 'city', 'is_default', 'country', 'region',
                ],
            ])
            ->assertJsonPath('data.label', 'New label')
            // Unchanged fields preserved.
            ->assertJsonPath('data.city', 'Dubai');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'label' => 'New label',
            'city' => 'Dubai',
        ]);
    });

    it('demotes the previous default when is_default=true is set on a non-default address', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressUpdateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $oldDefault = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
            'label' => 'Was default',
        ]);
        $promoted = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => false,
            'label' => 'To be default',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$promoted->id}", [
                'is_default' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_default', true);

        $oldDefault->refresh();
        $promoted->refresh();
        expect($oldDefault->is_default)->toBeFalse();
        expect($promoted->is_default)->toBeTrue();

        // Invariant: at most one default at any time.
        expect(Address::query()->where('user_id', $user->id)->where('is_default', true)->count())
            ->toBe(1);
    });

    it('returns 422 ERR_REGION_COUNTRY_MISMATCH when region_id is patched to a region of a different country', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressUpdateCustomer();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $saudi = Country::query()->where('code', 'sa')->firstOrFail();
        $saudiRegion = Region::query()->where('country_id', $saudi->id)->firstOrFail();

        $address = Address::factory()->forUser($user)->forCountry($uae)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$address->id}", [
                'region_id' => $saudiRegion->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_REGION_COUNTRY_MISMATCH');
    });

    it('returns 200 when patching region_id alone and the region still belongs to the current country', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressUpdateCustomer();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $regionA = Region::query()->where('country_id', $uae->id)->where('code', 'dxb')->firstOrFail();
        $regionB = Region::query()->where('country_id', $uae->id)->where('code', 'auh')->firstOrFail();

        $address = Address::factory()->forUser($user)->forCountry($uae)->create([
            'region_id' => $regionA->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$address->id}", [
                'region_id' => $regionB->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.region.code', 'auh');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'country_id' => $uae->id,
            'region_id' => $regionB->id,
        ]);
    });

    it('returns 403 and does not mutate the row when the caller does not own the address', function (): void {
        ['token' => $token] = makeAddressUpdateCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$address->id}", [
                'label' => 'Hacked',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);

        // Original row untouched.
        $this->assertDatabaseMissing('addresses', [
            'id' => $address->id,
            'label' => 'Hacked',
        ]);
    });

    it('returns code=ERR_FORBIDDEN on PATCH ownership failure (architecture doc § 5.4)', function (): void {
        ['token' => $token] = makeAddressUpdateCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/v1/me/addresses/{$address->id}", [
                'label' => 'Hacked',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the address id does not exist', function (): void {
        ['token' => $token] = makeAddressUpdateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->patchJson('/api/v1/me/addresses/999999', ['label' => 'X']);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED when unauthenticated', function (): void {
        ['user' => $user] = makeAddressUpdateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->create();

        $response = $this->patchJson("/api/v1/me/addresses/{$address->id}", [
            'label' => 'Updated',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
