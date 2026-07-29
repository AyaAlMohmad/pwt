<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Order model — recomputeTotals, scopes, factory states, is_cancellable.
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 8.1 + the Order model in
| app/Models/Order.php.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

describe('Order::recomputeTotals()', function (): void {
    it('sums line subtotals + tax base × tax_rate / 100', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED',
            'tax_rate' => '5.00',
            'discount_amount' => '0.00',
            'shipping_amount' => '0.00',
        ])->create();
        OrderItem::factory()->forOrder($order)->withUnitPrice(500.00, 2)->create();

        $totals = $order->refresh()->recomputeTotals();

        // subtotal = 1000; discount = 0; tax base = 1000; tax 5% = 50; total = 1050.
        expect($totals['subtotal'])->toBe('1000.00')
            ->and($totals['discount'])->toBe('0.00')
            ->and($totals['tax'])->toBe('50.00')
            ->and($totals['total'])->toBe('1050.00');
    });

    it('subtracts the order discount before tax base', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED',
            'tax_rate' => '5.00',
            'discount_amount' => '100.00',
            'shipping_amount' => '0.00',
        ])->create();
        OrderItem::factory()->forOrder($order)->withUnitPrice(1000.00, 1)->create();

        $totals = $order->refresh()->recomputeTotals();
        // subtotal = 1000; discount = 100; tax base = 900; tax = 45; total = 945.
        expect($totals['tax'])->toBe('45.00')->and($totals['total'])->toBe('945.00');
    });

    it('clamps the tax base to zero when discount exceeds subtotal', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED',
            'tax_rate' => '5.00',
            'discount_amount' => '5000.00', // > subtotal
            'shipping_amount' => '0.00',
        ])->create();
        OrderItem::factory()->forOrder($order)->withUnitPrice(100.00, 1)->create();

        $totals = $order->refresh()->recomputeTotals();
        expect($totals['tax'])->toBe('0.00');
    });
});

describe('Order scopes', function (): void {
    it('forUser() narrows to a single user_id', function (): void {
        $a = User::factory()->customer()->create();
        $b = User::factory()->customer()->create();
        $addrA = Address::factory()->forUser($a)->forCountry($this->uae)->create();
        $addrB = Address::factory()->forUser($b)->forCountry($this->uae)->create();
        Order::factory()->forUser($a)->forAddress($addrA)->state(['currency' => 'AED'])->create();
        Order::factory()->forUser($b)->forAddress($addrB)->state(['currency' => 'AED'])->create();

        expect(Order::query()->forUser($a->id)->count())->toBe(1)
            ->and(Order::query()->forUser($b->id)->count())->toBe(1);
    });

    it('withStatus() filters by a single status string', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();
        Order::factory()->forUser($u)->forAddress($addr)->state(['status' => 'pending', 'currency' => 'AED'])->create();
        Order::factory()->forUser($u)->forAddress($addr)->state([
            'status' => 'cancelled', 'cancelled_at' => now(), 'currency' => 'AED',
        ])->create();

        expect(Order::query()->withStatus('pending')->count())->toBe(1)
            ->and(Order::query()->withStatus('cancelled')->count())->toBe(1);
    });

    it('withStatus() supports a list of statuses', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();
        Order::factory()->forUser($u)->forAddress($addr)->state(['status' => 'pending', 'currency' => 'AED'])->create();
        Order::factory()->forUser($u)->forAddress($addr)->state([
            'status' => 'confirmed', 'confirmed_at' => now(), 'currency' => 'AED',
        ])->create();
        Order::factory()->forUser($u)->forAddress($addr)->state([
            'status' => 'cancelled', 'cancelled_at' => now(), 'currency' => 'AED',
        ])->create();

        expect(Order::query()->withStatus(['pending', 'confirmed'])->count())->toBe(2);
    });

    it('placedBetween() applies inclusive date bounds and accepts null bounds', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();
        Order::factory()->forUser($u)->forAddress($addr)->state([
            'currency' => 'AED', 'created_at' => now()->subDays(20),
        ])->create();
        Order::factory()->forUser($u)->forAddress($addr)->state([
            'currency' => 'AED', 'created_at' => now()->subDay(),
        ])->create();

        expect(Order::query()->placedBetween(now()->subDays(10)->format('Y-m-d'), null)->count())->toBe(1);
        expect(Order::query()->placedBetween(null, now()->format('Y-m-d'))->count())->toBe(2);
    });
});

describe('Order::getIsCancellableAttribute()', function (): void {
    it('returns true only for pending and confirmed states', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();

        $pending = Order::factory()->forUser($u)->forAddress($addr)->state(['status' => 'pending', 'currency' => 'AED'])->create();
        $confirmed = Order::factory()->forUser($u)->forAddress($addr)->state([
            'status' => 'confirmed', 'confirmed_at' => now(), 'currency' => 'AED',
        ])->create();
        $cancelled = Order::factory()->forUser($u)->forAddress($addr)->state([
            'status' => 'cancelled', 'cancelled_at' => now(), 'currency' => 'AED',
        ])->create();

        expect($pending->is_cancellable)->toBeTrue()
            ->and($confirmed->is_cancellable)->toBeTrue()
            ->and($cancelled->is_cancellable)->toBeFalse();
    });
});

describe('OrderFactory states', function (): void {
    it('pending() / confirmed() / installed() / completed() / cancelled() set the expected columns', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();

        $pending = Order::factory()->forUser($u)->forAddress($addr)->pending()->create(['currency' => 'AED']);
        expect($pending->status)->toBe(OrderStatus::Pending);

        $confirmed = Order::factory()->forUser($u)->forAddress($addr)->confirmed()->create(['currency' => 'AED']);
        expect($confirmed->status)->toBe(OrderStatus::Confirmed)
            ->and($confirmed->confirmed_at)->not->toBeNull();

        $cancelled = Order::factory()->forUser($u)->forAddress($addr)->cancelled()->create(['currency' => 'AED']);
        expect($cancelled->status)->toBe(OrderStatus::Cancelled)
            ->and($cancelled->cancelled_at)->not->toBeNull()
            ->and($cancelled->cancellation_reason)->not->toBeNull();

        $installed = Order::factory()->forUser($u)->forAddress($addr)->installed()->create(['currency' => 'AED']);
        expect($installed->status)->toBe(OrderStatus::Installed)
            ->and($installed->installed_at)->not->toBeNull();

        $completed = Order::factory()->forUser($u)->forAddress($addr)->completed()->create(['currency' => 'AED']);
        expect($completed->status)->toBe(OrderStatus::Completed)
            ->and($completed->completed_at)->not->toBeNull();
    });

    it('withItems() attaches the requested number of order_item rows', function (): void {
        $u = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($u)->forCountry($this->uae)->create();

        $order = Order::factory()
            ->forUser($u)
            ->forAddress($addr)
            ->state(['currency' => 'AED'])
            ->withItems(3)
            ->create();

        expect($order->items()->count())->toBe(3);
    });
});
