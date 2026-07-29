<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Product;
use App\Models\User;
use App\Policies\MaintenanceRequestPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new MaintenanceRequestPolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function policyMaintenanceRequest(User $owner): MaintenanceRequest
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();
    $product = Product::factory()->create();
    $machine = Machine::factory()->forUser($owner)->forProduct($product)
        ->state(['address_id' => $address->id])->create();

    return MaintenanceRequest::factory()->forUser($owner)->forMachine($machine)->create();
}

describe('MaintenanceRequestPolicy::viewAny()', function (): void {
    it('allows any authenticated user (the controller scopes by user_id)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->viewAny($customer))->toBeTrue();
    });
});

describe('MaintenanceRequestPolicy::view()', function (): void {
    it('allows the owner to view their own request', function (): void {
        $owner = User::factory()->customer()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->view($owner, $request))->toBeTrue();
    });

    it('denies another customer from viewing the request', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->view($stranger, $request))->toBeFalse();
    });

    it('denies a super_admin via the customer-side policy (admin uses permission keys)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->view($admin, $request))->toBeFalse();
    });
});

describe('MaintenanceRequestPolicy::create()', function (): void {
    it('allows any authenticated user (per-machine ownership is on the OwnedMachine rule)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->create($customer))->toBeTrue();
    });
});

describe('MaintenanceRequestPolicy::cancel()', function (): void {
    it('allows the owner to cancel their own request', function (): void {
        $owner = User::factory()->customer()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->cancel($owner, $request))->toBeTrue();
    });

    it('denies a stranger from cancelling the request', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->cancel($stranger, $request))->toBeFalse();
    });

    it('denies a super_admin from cancelling via the customer policy (admin permissions live elsewhere)', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $request = policyMaintenanceRequest($owner);

        expect($this->policy->cancel($admin, $request))->toBeFalse();
    });
});
