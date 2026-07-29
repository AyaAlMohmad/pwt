<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\User;
use App\Policies\AddressPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new AddressPolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

describe('AddressPolicy::viewAny()', function (): void {
    it('allows any authenticated user (controller scopes the query by user_id)', function (): void {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();

        expect($this->policy->viewAny($customer))->toBeTrue();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });
});

describe('AddressPolicy::view()', function (): void {
    it('allows the owner to view their own address', function (): void {
        $owner = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->view($owner, $address))->toBeTrue();
    });

    it('denies a different customer from viewing the address', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->view($stranger, $address))->toBeFalse();
    });

    it('denies a super_admin from viewing customer addresses through this gate (Phase 5 keeps the policy ownership-only)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->view($admin, $address))->toBeFalse();
    });
});

describe('AddressPolicy::create()', function (): void {
    it('allows any authenticated user (route-level auth handles the rest)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->create($customer))->toBeTrue();
    });
});

describe('AddressPolicy::update()', function (): void {
    it('allows the owner to update their own address', function (): void {
        $owner = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->update($owner, $address))->toBeTrue();
    });

    it('denies a different customer from updating the address', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->update($stranger, $address))->toBeFalse();
    });

    it('denies an admin from updating customer addresses (admin path is Phase 16+)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->update($admin, $address))->toBeFalse();
    });
});

describe('AddressPolicy::delete()', function (): void {
    it('allows the owner to delete their own address', function (): void {
        $owner = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->delete($owner, $address))->toBeTrue();
    });

    it('denies a different customer from deleting the address', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->delete($stranger, $address))->toBeFalse();
    });

    it('denies an admin from deleting customer addresses (admin path is Phase 16+)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $address = Address::factory()->forUser($owner)->forCountry($this->uae)->create();

        expect($this->policy->delete($admin, $address))->toBeFalse();
    });
});
