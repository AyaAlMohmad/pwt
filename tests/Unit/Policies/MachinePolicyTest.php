<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Product;
use App\Models\User;
use App\Policies\MachinePolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new MachinePolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function policyMachine(User $owner): Machine
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();
    $product = Product::factory()->create();

    return Machine::factory()->forUser($owner)->forProduct($product)
        ->state(['address_id' => $address->id])->create();
}

describe('MachinePolicy::viewAny()', function (): void {
    it('allows any authenticated user (controller scopes the query by user_id)', function (): void {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();

        expect($this->policy->viewAny($customer))->toBeTrue();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });
});

describe('MachinePolicy::view()', function (): void {
    it('allows the owner to view their own machine', function (): void {
        $owner = User::factory()->customer()->create();
        $machine = policyMachine($owner);

        expect($this->policy->view($owner, $machine))->toBeTrue();
    });

    it('denies a different customer from viewing the machine', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $machine = policyMachine($owner);

        expect($this->policy->view($stranger, $machine))->toBeFalse();
    });

    it('denies a super_admin from viewing a customer machine through the customer policy', function (): void {
        // Customer-side policy keeps view() ownership-only. Admin Phase 18
        // endpoints use the admin permission keys (`machines.view-any`).
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $machine = policyMachine($owner);

        expect($this->policy->view($admin, $machine))->toBeFalse();
    });
});
