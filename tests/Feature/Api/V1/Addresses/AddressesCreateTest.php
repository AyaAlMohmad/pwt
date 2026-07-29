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
 * Create a customer + Sanctum token.
 *
 * @return array{user: User, token: string}
 */
function makeAddressCreateCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/**
 * Build a baseline valid payload for POST /me/addresses.
 *
 * @return array<string, mixed>
 */
function validAddressPayload(int $countryId, array $overrides = []): array
{
    return array_merge([
        'label' => 'Home',
        'country_id' => $countryId,
        'recipient_name' => 'Ahmed Saleh',
        'recipient_phone' => '+971501234567',
        'recipient_phone_country_code' => '+971',
        'line1' => 'Villa 42, Street 5',
        'city' => 'Dubai',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| POST /api/v1/me/addresses
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/me/addresses', function (): void {
    it('returns 201 with the AddressResource envelope on a minimal valid payload', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'label', 'recipient_name', 'recipient_phone',
                    'recipient_phone_country_code', 'line1', 'line2', 'city',
                    'area', 'postal_code', 'notes', 'country', 'region',
                    'is_default', 'latitude', 'longitude', 'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.label', 'Home')
            ->assertJsonPath('data.city', 'Dubai');

        // The first address is automatically promoted to default.
        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'label' => 'Home',
            'is_default' => true,
        ]);
    });

    it('respects an explicit is_default=true when no other addresses exist', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'is_default' => true,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.is_default', true);

        expect(Address::query()->where('user_id', $user->id)->where('is_default', true)->count())
            ->toBe(1);
    });

    it('un-defaults the previous default when a second address is created with is_default=true', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $first = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
            'label' => 'Old default',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'label' => 'New default',
                'is_default' => true,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.label', 'New default');

        // Previous default has been demoted.
        $first->refresh();
        expect($first->is_default)->toBeFalse();

        // Exactly one default exists overall.
        expect(Address::query()->where('user_id', $user->id)->where('is_default', true)->count())
            ->toBe(1);
    });

    it('keeps the previous default when a second address is created with is_default=false', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $first = Address::factory()->forUser($user)->forCountry($country)->create([
            'is_default' => true,
            'label' => 'Sticky default',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'label' => 'Secondary',
                'is_default' => false,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.is_default', false);

        $first->refresh();
        expect($first->is_default)->toBeTrue();
    });

    it('accepts a region_id that belongs to the supplied country_id', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $region = Region::query()->where('country_id', $country->id)
            ->where('code', 'dxb')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'region_id' => $region->id,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.region.code', 'dxb');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'region_id' => $region->id,
            'country_id' => $country->id,
        ]);
    });

    it('returns 422 ERR_REGION_COUNTRY_MISMATCH when the region does not belong to the country', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $saudi = Country::query()->where('code', 'sa')->firstOrFail();
        // Pick a Saudi region.
        $saudiRegion = Region::query()->where('country_id', $saudi->id)->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($uae->id, [
                'region_id' => $saudiRegion->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_REGION_COUNTRY_MISMATCH')
            ->assertJsonStructure(['errors' => ['region_id']]);

        expect($response->json('errors.region_id.0'))
            ->toBe(__('addresses.region_country_mismatch'));
    });

    it('returns 422 ERR_VALIDATION for an inactive country_id', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $country->update(['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['country_id']]);
    });

    it('returns 422 ERR_ADDRESS_LIMIT_REACHED when the customer already has 10 addresses', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        // Seed exactly 10 active addresses.
        Address::factory()->count(10)->forUser($user)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'label' => 'Overflow',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_ADDRESS_LIMIT_REACHED');

        // Still 10 — overflow row not persisted.
        expect(Address::query()->where('user_id', $user->id)->count())->toBe(10);
    });

    it('returns 422 ERR_VALIDATION for an invalid phone format', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'recipient_phone' => 'not-a-phone',
                'recipient_phone_country_code' => '+971',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['recipient_phone']]);
    });

    it('returns 422 ERR_VALIDATION with per-field errors for missing required fields', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure([
                'errors' => [
                    'label',
                    'country_id',
                    'recipient_name',
                    'recipient_phone',
                    'recipient_phone_country_code',
                    'line1',
                    'city',
                ],
            ]);
    });

    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->postJson('/api/v1/me/addresses', validAddressPayload($country->id));

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');

        $this->assertDatabaseCount('addresses', 0);
    });

    it('ignores a client-supplied user_id and binds the row to the authenticated caller', function (): void {
        ['user' => $caller, 'token' => $token] = makeAddressCreateCustomer();
        $other = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'user_id' => $other->id,
                'label' => 'Spoof attempt',
            ]));

        $response->assertCreated();

        // Row was created against caller, not the spoofed user.
        $this->assertDatabaseHas('addresses', [
            'user_id' => $caller->id,
            'label' => 'Spoof attempt',
        ]);
        $this->assertDatabaseMissing('addresses', [
            'user_id' => $other->id,
            'label' => 'Spoof attempt',
        ]);
    });

    it('returns 422 ERR_VALIDATION when latitude is supplied without longitude', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/me/addresses', validAddressPayload($country->id, [
                'latitude' => 25.2048,
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['longitude']]);
    });

    it('returns Arabic error message body when Accept-Language: ar and a region mismatch fires', function (): void {
        ['token' => $token] = makeAddressCreateCustomer();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $saudi = Country::query()->where('code', 'sa')->firstOrFail();
        $saudiRegion = Region::query()->where('country_id', $saudi->id)->firstOrFail();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/me/addresses', validAddressPayload($uae->id, [
                'region_id' => $saudiRegion->id,
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_REGION_COUNTRY_MISMATCH');

        // The Arabic translation should be in the response — not the English one.
        $message = (string) $response->json('message');
        expect($message)->not->toBe('The selected region does not belong to the selected country.');
        expect($message)->toContain('المنطقة');
    });
});
