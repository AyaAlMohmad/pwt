<?php

declare(strict_types=1);

use App\Exceptions\Addresses\AddressLimitReachedException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Region;
use App\Models\User;
use App\Services\Addresses\AddressService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->service = new AddressService;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * Build a baseline valid service payload.
 *
 * @return array<string, mixed>
 */
function servicePayload(int $countryId, array $overrides = []): array
{
    return array_merge([
        'label' => 'Home',
        'country_id' => $countryId,
        'recipient_name' => 'Ahmed Saleh',
        'recipient_phone' => '+971501234567',
        'recipient_phone_country_code' => '+971',
        'line1' => 'Villa 42',
        'city' => 'Dubai',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| AddressService::listForUser()
|--------------------------------------------------------------------------
*/

describe('AddressService::listForUser()', function (): void {
    it('returns an empty collection when the user has no addresses', function (): void {
        $user = User::factory()->customer()->create();

        $list = $this->service->listForUser($user);

        expect($list)->toHaveCount(0);
    });

    it('returns the user\'s addresses sorted default-first then created_at DESC', function (): void {
        $user = User::factory()->customer()->create();
        $a = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
            'created_at' => now()->subDays(3),
        ]);
        $b = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
            'created_at' => now()->subDays(2),
        ]);
        $c = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
            'created_at' => now()->subDay(),
        ]);

        $list = $this->service->listForUser($user);

        expect($list->pluck('id')->all())->toBe([$b->id, $c->id, $a->id]);
    });

    it('eager-loads country and region to avoid N+1 queries', function (): void {
        $user = User::factory()->customer()->create();
        $region = Region::query()->where('country_id', $this->uae->id)->first();
        Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'region_id' => $region?->id,
        ]);

        $list = $this->service->listForUser($user);

        expect($list->first()->relationLoaded('country'))->toBeTrue();
        expect($list->first()->relationLoaded('region'))->toBeTrue();
    });

    it('only returns addresses owned by the supplied user', function (): void {
        $self = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        Address::factory()->forUser($self)->forCountry($this->uae)->create();
        Address::factory()->forUser($other)->forCountry($this->uae)->create();

        $list = $this->service->listForUser($self);

        expect($list)->toHaveCount(1);
        expect($list->first()->user_id)->toBe($self->id);
    });
});

/*
|--------------------------------------------------------------------------
| AddressService::create()
|--------------------------------------------------------------------------
*/

describe('AddressService::create()', function (): void {
    it('persists the address and binds it to the supplied user', function (): void {
        $user = User::factory()->customer()->create();

        $address = $this->service->create($user, servicePayload($this->uae->id));

        expect($address)->toBeInstanceOf(Address::class);
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'user_id' => $user->id,
            'label' => 'Home',
        ]);
    });

    it('eager-loads country/region on the returned model', function (): void {
        $user = User::factory()->customer()->create();
        $region = Region::query()->where('country_id', $this->uae->id)->first();

        $address = $this->service->create($user, servicePayload($this->uae->id, [
            'region_id' => $region?->id,
        ]));

        expect($address->relationLoaded('country'))->toBeTrue();
        expect($address->relationLoaded('region'))->toBeTrue();
    });

    it('auto-promotes the first address to default even when is_default=false is supplied', function (): void {
        $user = User::factory()->customer()->create();

        $address = $this->service->create($user, servicePayload($this->uae->id, [
            'is_default' => false,
        ]));

        expect($address->is_default)->toBeTrue();
    });

    it('demotes the previous default when a new address is created with is_default=true', function (): void {
        $user = User::factory()->customer()->create();
        $oldDefault = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);

        $new = $this->service->create($user, servicePayload($this->uae->id, [
            'is_default' => true,
            'label' => 'New default',
        ]));

        $oldDefault->refresh();
        expect($oldDefault->is_default)->toBeFalse();
        expect($new->is_default)->toBeTrue();
        expect(Address::query()->where('user_id', $user->id)->where('is_default', true)->count())
            ->toBe(1);
    });

    it('leaves the existing default in place when a new address is created with is_default=false', function (): void {
        $user = User::factory()->customer()->create();
        $oldDefault = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);

        $new = $this->service->create($user, servicePayload($this->uae->id, [
            'is_default' => false,
            'label' => 'Secondary',
        ]));

        $oldDefault->refresh();
        expect($oldDefault->is_default)->toBeTrue();
        expect($new->is_default)->toBeFalse();
    });

    it('throws AddressLimitReachedException when the user already has 10 addresses', function (): void {
        $user = User::factory()->customer()->create();
        Address::factory()->count(10)->forUser($user)->forCountry($this->uae)->create();

        $this->service->create($user, servicePayload($this->uae->id));
    })->throws(AddressLimitReachedException::class);

    it('does not insert a row when the cap exception fires (transaction rollback)', function (): void {
        $user = User::factory()->customer()->create();
        Address::factory()->count(10)->forUser($user)->forCountry($this->uae)->create();

        try {
            $this->service->create($user, servicePayload($this->uae->id, [
                'label' => 'Overflow',
            ]));
        } catch (AddressLimitReachedException) {
            // Expected — fall through.
        }

        expect(Address::query()->where('user_id', $user->id)->count())->toBe(10);
        $this->assertDatabaseMissing('addresses', [
            'user_id' => $user->id,
            'label' => 'Overflow',
        ]);
    });

    it('strips any client-supplied id/user_id from the payload', function (): void {
        $user = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();

        $address = $this->service->create($user, servicePayload($this->uae->id, [
            'id' => 12345,
            'user_id' => $other->id,
        ]));

        expect($address->user_id)->toBe($user->id);
        expect($address->id)->not->toBe(12345);
    });
});

/*
|--------------------------------------------------------------------------
| AddressService::update()
|--------------------------------------------------------------------------
*/

describe('AddressService::update()', function (): void {
    it('applies a partial update without touching omitted fields', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'label' => 'Old',
            'city' => 'Dubai',
        ]);

        $updated = $this->service->update($address, ['label' => 'New']);

        expect($updated->label)->toBe('New');
        expect($updated->city)->toBe('Dubai');
    });

    it('demotes the previous default when is_default=true is set on a non-default address', function (): void {
        $user = User::factory()->customer()->create();
        $oldDefault = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);
        $promoted = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
        ]);

        $this->service->update($promoted, ['is_default' => true]);

        $oldDefault->refresh();
        $promoted->refresh();
        expect($oldDefault->is_default)->toBeFalse();
        expect($promoted->is_default)->toBeTrue();
    });

    it('does not re-promote any other address when is_default=false is set on the current default', function (): void {
        $user = User::factory()->customer()->create();
        $current = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);
        $sibling = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
        ]);

        $this->service->update($current, ['is_default' => false]);

        $current->refresh();
        $sibling->refresh();
        expect($current->is_default)->toBeFalse();
        expect($sibling->is_default)->toBeFalse();
    });

    it('strips server-managed keys (user_id, id) from the payload', function (): void {
        $user = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();

        $updated = $this->service->update($address, [
            'user_id' => $other->id,
            'id' => 9999,
            'label' => 'X',
        ]);

        expect($updated->user_id)->toBe($user->id);
        expect($updated->id)->toBe($address->id);
        expect($updated->label)->toBe('X');
    });

    it('returns the model with country/region eager-loaded', function (): void {
        $user = User::factory()->customer()->create();
        $region = Region::query()->where('country_id', $this->uae->id)->first();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'region_id' => $region?->id,
        ]);

        $updated = $this->service->update($address, ['label' => 'Loaded']);

        expect($updated->relationLoaded('country'))->toBeTrue();
        expect($updated->relationLoaded('region'))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| AddressService::delete()
|--------------------------------------------------------------------------
*/

describe('AddressService::delete()', function (): void {
    it('soft-deletes the address and returns null when it was not the default', function (): void {
        $user = User::factory()->customer()->create();
        Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);
        $victim = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
        ]);

        $promotedId = $this->service->delete($victim);

        expect($promotedId)->toBeNull();
        $this->assertSoftDeleted('addresses', ['id' => $victim->id]);
    });

    it('auto-promotes the next-most-recent surviving address when the default is deleted', function (): void {
        $user = User::factory()->customer()->create();
        $default = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
            'created_at' => now()->subDays(5),
        ]);
        $older = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
            'created_at' => now()->subDays(3),
        ]);
        $newer = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => false,
            'created_at' => now()->subDay(),
        ]);

        $promotedId = $this->service->delete($default);

        expect($promotedId)->toBe($newer->id);
        $newer->refresh();
        $older->refresh();
        expect($newer->is_default)->toBeTrue();
        expect($older->is_default)->toBeFalse();
    });

    it('returns null when the deleted address is the user\'s only address (no candidate to promote)', function (): void {
        $user = User::factory()->customer()->create();
        $only = Address::factory()->forUser($user)->forCountry($this->uae)->create([
            'is_default' => true,
        ]);

        $promotedId = $this->service->delete($only);

        expect($promotedId)->toBeNull();
        $this->assertSoftDeleted('addresses', ['id' => $only->id]);
    });
});

/*
|--------------------------------------------------------------------------
| AddressService::isAddressInUse() — Phase 8 stub
|--------------------------------------------------------------------------
*/

describe('AddressService::isAddressInUse()', function (): void {
    it('always returns false in Phase 5 (Phase 8 will inject the real Orders check)', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();

        expect($this->service->isAddressInUse($address))->toBeFalse();
    });
});
