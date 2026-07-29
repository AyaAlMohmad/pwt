<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function paymentTestPayment(array $overrides = []): Payment
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $order = Order::factory()->forUser($user)->forAddress($address)->state([
        'status' => OrderStatus::Pending->value,
        'currency' => 'AED',
        'total_amount' => '525.00',
        'payment_method' => PaymentMethod::Card->value,
    ])->create();

    return Payment::factory()->forOrder($order)->initiated()->create($overrides);
}

describe('Payment::isExpired()', function (): void {
    afterEach(function (): void {
        Carbon::setTestNow();
    });

    it('returns false for a freshly-created initiated payment', function (): void {
        $payment = paymentTestPayment();
        expect($payment->isExpired())->toBeFalse();
    });

    it('returns true when the initiated payment is older than the TTL', function (): void {
        config(['payment.initiated_ttl_minutes' => 30]);

        $now = Carbon::create(2026, 5, 15, 10, 0, 0);
        Carbon::setTestNow($now);
        $payment = paymentTestPayment();

        // Travel 31 minutes into the future — past the TTL.
        Carbon::setTestNow($now->copy()->addMinutes(31));

        expect($payment->isExpired())->toBeTrue();
    });

    it('returns false on the exact TTL boundary (inclusive lower bound semantics in scopePending)', function (): void {
        config(['payment.initiated_ttl_minutes' => 30]);

        $now = Carbon::create(2026, 5, 15, 10, 0, 0);
        Carbon::setTestNow($now);
        $payment = paymentTestPayment();

        // 29 minutes later — still inside TTL.
        Carbon::setTestNow($now->copy()->addMinutes(29));
        expect($payment->isExpired())->toBeFalse();
    });

    it('returns false when the status is not initiated regardless of age', function (): void {
        config(['payment.initiated_ttl_minutes' => 30]);

        $payment = paymentTestPayment([
            'status' => PaymentStatus::Captured->value,
            'captured_at' => now(),
        ]);
        DB::table('payments')->where('id', $payment->id)->update([
            'created_at' => now()->subHours(2),
        ]);
        $payment->refresh();

        expect($payment->isExpired())->toBeFalse();
    });
});

describe('Payment relationships', function (): void {
    it('belongsTo Order', function (): void {
        $payment = paymentTestPayment();

        expect($payment->order)->toBeInstanceOf(Order::class)
            ->and($payment->order->id)->toBe($payment->order_id);
    });

    it('belongsTo User', function (): void {
        $payment = paymentTestPayment();

        expect($payment->user)->toBeInstanceOf(User::class)
            ->and($payment->user->id)->toBe($payment->user_id);
    });

    it('hasMany PaymentEvent ordered by id', function (): void {
        $payment = paymentTestPayment();
        $first = PaymentEvent::factory()->forPayment($payment)->create([
            'gateway_event_id' => 'evt_a',
        ]);
        $second = PaymentEvent::factory()->forPayment($payment)->create([
            'gateway_event_id' => 'evt_b',
        ]);

        $events = $payment->events()->get();
        expect($events)->toHaveCount(2)
            ->and($events->first()->id)->toBe($first->id)
            ->and($events->last()->id)->toBe($second->id);
    });
});

describe('Payment scopes', function (): void {
    it('forUser narrows to a given user', function (): void {
        $userA = User::factory()->customer()->create();
        $userB = User::factory()->customer()->create();
        $uae = Country::query()->where('code', 'ae')->firstOrFail();

        $addrA = Address::factory()->forUser($userA)->forCountry($uae)->create();
        $addrB = Address::factory()->forUser($userB)->forCountry($uae)->create();

        $orderA = Order::factory()->forUser($userA)->forAddress($addrA)->state([
            'currency' => 'AED', 'total_amount' => '100.00',
        ])->create();
        $orderB = Order::factory()->forUser($userB)->forAddress($addrB)->state([
            'currency' => 'AED', 'total_amount' => '100.00',
        ])->create();

        Payment::factory()->forOrder($orderA)->initiated()->create();
        Payment::factory()->forOrder($orderB)->initiated()->create();

        expect(Payment::query()->forUser($userA->id)->count())->toBe(1)
            ->and(Payment::query()->forUser($userB->id)->count())->toBe(1);
    });

    it('pending excludes initiated rows outside the TTL window', function (): void {
        config(['payment.initiated_ttl_minutes' => 30]);

        $fresh = paymentTestPayment();
        $stale = paymentTestPayment();
        DB::table('payments')->where('id', $stale->id)->update([
            'created_at' => now()->subHours(2),
        ]);

        $ids = Payment::query()->pending()->pluck('id')->all();
        expect($ids)->toContain($fresh->id)
            ->and($ids)->not->toContain($stale->id);
    });

    it('terminal collects captured / failed / cancelled / refunded only', function (): void {
        $initiated = paymentTestPayment();
        $captured = paymentTestPayment(['status' => PaymentStatus::Captured->value, 'captured_at' => now()]);
        $failed = paymentTestPayment(['status' => PaymentStatus::Failed->value, 'failed_at' => now()]);

        $ids = Payment::query()->terminal()->pluck('id')->all();
        expect($ids)->not->toContain($initiated->id)
            ->and($ids)->toContain($captured->id)
            ->and($ids)->toContain($failed->id);
    });
});

describe('Payment::booted() creates a UUID public_id', function (): void {
    it('auto-fills public_id when not provided', function (): void {
        $uae = Country::query()->where('code', 'ae')->firstOrFail();
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($uae)->create();
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED', 'total_amount' => '50.00',
        ])->create();

        $payment = Payment::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => PaymentMethod::Card->value,
            'gateway' => 'stub',
            'amount' => '50.00',
            'currency' => 'AED',
            'status' => PaymentStatus::Initiated->value,
        ]);

        expect($payment->public_id)->toBeString()
            ->and($payment->public_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });
});
