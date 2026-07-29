<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Policies\PaymentPolicy;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->policy = new PaymentPolicy;
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function policyPayment(User $owner): Payment
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $address = Address::factory()->forUser($owner)->forCountry($uae)->create();
    $order = Order::factory()->forUser($owner)->forAddress($address)->state([
        'status' => OrderStatus::Pending->value,
        'currency' => 'AED',
        'total_amount' => '100.00',
    ])->create();

    return Payment::factory()->forOrder($order)->initiated()->create();
}

describe('PaymentPolicy::view()', function (): void {
    it('allows the owner to view their payment', function (): void {
        $owner = User::factory()->customer()->create();
        $payment = policyPayment($owner);

        expect($this->policy->view($owner, $payment))->toBeTrue();
    });

    it('denies a stranger from viewing the payment', function (): void {
        $owner = User::factory()->customer()->create();
        $stranger = User::factory()->customer()->create();
        $payment = policyPayment($owner);

        expect($this->policy->view($stranger, $payment))->toBeFalse();
    });

    it('denies a super_admin from reading customer payments through the customer policy', function (): void {
        // Customer-side policy is ownership-only. Admin reads land in
        // Phase 17 with their own permission keys.
        $owner = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $payment = policyPayment($owner);

        expect($this->policy->view($admin, $payment))->toBeFalse();
    });
});

describe('PaymentPolicy::create()', function (): void {
    it('allows any authenticated user to initiate a payment (route + service guard the actual rules)', function (): void {
        $customer = User::factory()->customer()->create();
        expect($this->policy->create($customer))->toBeTrue();
    });
});
