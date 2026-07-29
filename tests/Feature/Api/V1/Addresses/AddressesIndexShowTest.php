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
 * Create a customer + Sanctum token for use in feature tests.
 *
 * @return array{user: User, token: string}
 */
function makeAddressCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/addresses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/me/addresses', function (): void {
    it('returns 200 with empty data[] when the customer has no addresses', function (): void {
        ['token' => $token] = makeAddressCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/addresses');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);

        expect($response->json('data'))->toBe([]);
        expect($response->json())->toBeSuccessEnvelope();
    });

    it('returns the address list sorted with default first, then created_at DESC', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        // Three addresses with distinct created_at timestamps and one default.
        $oldest = Address::factory()->forUser($user)->forCountry($country)->create([
            'label' => 'Oldest non-default',
            'is_default' => false,
            'created_at' => now()->subDays(3),
        ]);
        $middle = Address::factory()->forUser($user)->forCountry($country)->create([
            'label' => 'Middle default',
            'is_default' => true,
            'created_at' => now()->subDays(2),
        ]);
        $newest = Address::factory()->forUser($user)->forCountry($country)->create([
            'label' => 'Newest non-default',
            'is_default' => false,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/addresses');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'label', 'recipient_name', 'recipient_phone',
                        'recipient_phone_country_code', 'line1', 'line2', 'city',
                        'area', 'postal_code', 'notes', 'country', 'region',
                        'is_default', 'latitude', 'longitude', 'created_at',
                        'updated_at',
                    ],
                ],
            ]);

        $data = $response->json('data');
        expect($data)->toHaveCount(3);

        // Default first, then non-default sorted by created_at DESC.
        expect($data[0]['id'])->toBe($middle->id);
        expect($data[0]['is_default'])->toBeTrue();
        expect($data[1]['id'])->toBe($newest->id);
        expect($data[2]['id'])->toBe($oldest->id);
    });

    it('never exposes user_id, country_id, region_id, or deleted_at on the list response', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        Address::factory()->forUser($user)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/addresses');

        $row = $response->json('data.0');
        expect($row)->not->toHaveKey('user_id')
            ->and($row)->not->toHaveKey('country_id')
            ->and($row)->not->toHaveKey('region_id')
            ->and($row)->not->toHaveKey('deleted_at');
    });

    it('returns the nested country/region names in Arabic when Accept-Language: ar', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $region = Region::query()->where('country_id', $country->id)
            ->where('code', 'dxb')
            ->firstOrFail();

        Address::factory()->forUser($user)->forCountry($country)->create([
            'region_id' => $region->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/me/addresses');

        $response->assertOk();
        expect($response->json('data.0.country.name'))->toBe('الإمارات العربية المتحدة');
        expect($response->json('data.0.region.name'))->toBe('دبي');
    });

    it('hides addresses owned by other users (caller-scoped query)', function (): void {
        ['user' => $self, 'token' => $token] = makeAddressCustomer();
        $stranger = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();

        Address::factory()->forUser($self)->forCountry($country)->create(['label' => 'Mine']);
        Address::factory()->forUser($stranger)->forCountry($country)->create(['label' => 'Theirs']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/addresses');

        $response->assertOk();
        $labels = collect($response->json('data'))->pluck('label')->all();
        expect($labels)->toBe(['Mine']);
    });

    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $response = $this->getJson('/api/v1/me/addresses');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');

        expect($response->json())->toBeErrorEnvelope('ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/me/addresses/{id}
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/me/addresses/{id}', function (): void {
    it('returns 200 with the AddressResource envelope when the caller owns the address', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $region = Region::query()->where('country_id', $country->id)->first();

        $address = Address::factory()->forUser($user)->forCountry($country)->create([
            'region_id' => $region?->id,
            'label' => 'Home',
            'is_default' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/addresses/{$address->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'label', 'recipient_name', 'recipient_phone',
                    'recipient_phone_country_code', 'line1', 'line2', 'city',
                    'area', 'postal_code', 'notes', 'country', 'region',
                    'is_default', 'latitude', 'longitude', 'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.id', $address->id)
            ->assertJsonPath('data.label', 'Home')
            ->assertJsonPath('data.is_default', true);

        // No leaked fields.
        $data = $response->json('data');
        expect($data)->not->toHaveKey('user_id')
            ->and($data)->not->toHaveKey('country_id')
            ->and($data)->not->toHaveKey('region_id')
            ->and($data)->not->toHaveKey('deleted_at');
    });

    it('returns 403 with the standard error envelope when the caller does not own the address', function (): void {
        ['token' => $token] = makeAddressCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false);

        // Production-code bug (reported to orchestrator): Laravel 11 wraps
        // AuthorizationException into AccessDeniedHttpException before the
        // user-registered render callback fires, so `code` currently lands
        // on ERR_HTTP instead of the contract-mandated ERR_FORBIDDEN. The
        // dedicated assertion lives in the skipped test below so the gap is
        // visible until the controller (or global handler) is fixed.
        expect($response->json())->toBeErrorEnvelope();
    });

    it('returns code=ERR_FORBIDDEN on ownership failure (architecture doc § 5.3)', function (): void {
        ['token' => $token] = makeAddressCustomer();
        $owner = User::factory()->customer()->create();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($owner)->forCountry($country)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the id does not exist', function (): void {
        ['token' => $token] = makeAddressCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/me/addresses/999999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 ERR_NOT_FOUND when the row is soft-deleted', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->softDeleted()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED when unauthenticated', function (): void {
        ['user' => $user] = makeAddressCustomer();
        $country = Country::query()->where('code', 'ae')->firstOrFail();
        $address = Address::factory()->forUser($user)->forCountry($country)->create();

        $response = $this->getJson("/api/v1/me/addresses/{$address->id}");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
