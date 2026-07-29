<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->policy = new OrderPolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function policyOrder(User $owner): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();

    return Order::factory()->forUser($owner)->forAddress($address)->state([
        'currency' => 'AED',
    ])->create();
}

describe('OrderPolicy::viewAny()', function (): void {
    it('allows any authenticated user (controller scopes the query by user_id)', function (): void {
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();

        expect($this->policy->viewAny($customer))->toBeTrue();
        expect($this->policy->viewAny($admin))->toBeTrue();
    });
});

describe('OrderPolicy::view()', function (): void {
    it('allows the owner to view their own order', function (): void {
        $owner = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->view($owner, $order))->toBeTrue();
    });

    it('denies a different customer from viewing the order', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->view($stranger, $order))->toBeFalse();
    });

    it('denies a super_admin from viewing customer orders through the customer policy', function (): void {
        // Customer-side policy keeps view() ownership-only. The Phase 17
        // admin endpoints use the admin permission keys (`orders.view-any`).
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $order = policyOrder($owner);

        expect($this->policy->view($admin, $order))->toBeFalse();
    });
});

describe('OrderPolicy::create()', function (): void {
    it('allows any authenticated user to create an order', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->create($customer))->toBeTrue();
    });
});

describe('OrderPolicy::cancel()', function (): void {
    it('allows the owner to cancel their own order', function (): void {
        $owner = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->cancel($owner, $order))->toBeTrue();
    });

    it('denies a stranger from cancelling the order', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->cancel($stranger, $order))->toBeFalse();
    });
});

describe('OrderPolicy::track()', function (): void {
    it('allows the owner to track their own order', function (): void {
        $owner = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->track($owner, $order))->toBeTrue();
    });

    it('denies a stranger from tracking the order', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $order = policyOrder($owner);

        expect($this->policy->track($stranger, $order))->toBeFalse();
    });
});

describe('OrderPolicy::update()', function (): void {
    it('denies a customer from updating an order (admin permission required)', function (): void {
        $customer = User::factory()->customer()->create();
        $order = policyOrder($customer);

        // The customer role does not hold orders.update-status — that's
        // the admin-only permission key per the Phase 17 spec.
        expect($this->policy->update($customer, $order))->toBeFalse();
    });

    it('allows a super_admin (who holds orders.update-status) to update an order', function (): void {
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $order = policyOrder($owner);

        expect($this->policy->update($admin, $order))->toBeTrue();
    });
});
