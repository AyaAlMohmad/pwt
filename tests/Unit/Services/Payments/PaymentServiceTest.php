<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\Payments\OrderNotPayableException;
use App\Exceptions\Payments\PaymentAlreadyInitiatedException;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Orders\OrderStatusTransitionService;
use App\Services\Payments\Drivers\StubDriver;
use App\Services\Payments\PaymentService;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
|--------------------------------------------------------------------------
| PaymentService — service-level tests
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/payments.md § 7.3, § 7.6.
| The service drives state mutations + transaction wrapping; webhook
| signature verification lives in middleware (covered separately).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    config(['payment.default' => 'stub']);

    $this->service = new PaymentService(
        new StubDriver,
        new OrderStatusTransitionService,
    );

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function svcMakeUserOrder(string $method = 'card', string $status = 'pending'): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $order = Order::factory()->forUser($user)->forAddress($address)->state([
        'status' => $status,
        'currency' => 'AED',
        'subtotal_amount' => '500.00',
        'discount_amount' => '0.00',
        'tax_amount' => '25.00',
        'tax_rate' => '5.00',
        'shipping_amount' => '0.00',
        'total_amount' => '525.00',
        'payment_method' => $method,
    ])->create();

    return [$user, $order];
}

describe('PaymentService::initiate() — COD branch', function (): void {
    it('creates a captured COD payment, auto-confirms the order, and writes a payment_events row', function (): void {
        [$user, $order] = svcMakeUserOrder('cod');

        $payment = $this->service->initiate($user, $order, PaymentMethod::Cod, null);

        expect($payment->status)->toBe(PaymentStatus::Captured)
            ->and($payment->gateway)->toBe('cod')
            ->and((string) $payment->amount)->toBe('525.00')
            ->and($payment->captured_at)->not->toBeNull()
            ->and($payment->gateway_transaction_id)->toStartWith('cod_');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'manual',
            'source' => 'customer',
        ]);
    });
});

describe('PaymentService::initiate() — online branch', function (): void {
    it('creates an initiated payment, calls the gateway, and stores the returned payment_url + txn id', function (): void {
        [$user, $order] = svcMakeUserOrder('card');

        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);

        expect($payment->status)->toBe(PaymentStatus::Initiated)
            ->and($payment->gateway)->toBe('stub')
            ->and($payment->gateway_transaction_id)->toStartWith('stub_')
            ->and($payment->client_payment_url)->toBeString()
            ->and($payment->client_payment_url)->toContain('?session=');

        // Order stays pending — the webhook flips it later.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'system',
            'source' => 'stub',
        ]);
    });
});

describe('PaymentService::initiate() — idempotency replay', function (): void {
    it('returns the same Payment within the 24h window with wasRecentlyCreated=false', function (): void {
        [$user, $order] = svcMakeUserOrder('cod');

        $key = 'idem-cod-'.uniqid();
        $first = $this->service->initiate($user, $order, PaymentMethod::Cod, $key);
        $second = $this->service->initiate($user, $order, PaymentMethod::Cod, $key);

        expect($second->id)->toBe($first->id)
            ->and($second->wasRecentlyCreated)->toBeFalse();

        // Single row.
        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
    });
});

describe('PaymentService::initiate() — order state guards', function (): void {
    it('throws OrderNotPayableException when the order is not pending', function (): void {
        [$user, $order] = svcMakeUserOrder('card', OrderStatus::Confirmed->value);

        expect(fn () => $this->service->initiate($user, $order, PaymentMethod::Card, null))
            ->toThrow(OrderNotPayableException::class);
    });

    it('throws AuthorizationException when the order belongs to another user', function (): void {
        [$owner, $order] = svcMakeUserOrder('card');
        $stranger = User::factory()->customer()->create();

        expect(fn () => $this->service->initiate($stranger, $order, PaymentMethod::Card, null))
            ->toThrow(AuthorizationException::class);
    });

    it('throws PaymentAlreadyInitiatedException when a non-expired initiated payment exists', function (): void {
        [$user, $order] = svcMakeUserOrder('card');

        // First initiate creates an `initiated` row.
        $this->service->initiate($user, $order, PaymentMethod::Card, null);

        expect(fn () => $this->service->initiate($user, $order, PaymentMethod::Card, null))
            ->toThrow(PaymentAlreadyInitiatedException::class);
    });

    it('allows a new initiate when the previous payment is failed (retryable terminal)', function (): void {
        [$user, $order] = svcMakeUserOrder('card');

        // Pre-existing failed payment shouldn't block re-attempt.
        Payment::factory()->forOrder($order)->failed()->create();

        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);
        expect($payment->status)->toBe(PaymentStatus::Initiated);
    });

    it('allows a new initiate after the previous initiated payment falls outside the TTL', function (): void {
        [$user, $order] = svcMakeUserOrder('card');

        // Old initiated row outside the 30-min TTL.
        $stale = Payment::factory()->forOrder($order)->initiated()->create();
        DB::table('payments')->where('id', $stale->id)->update([
            'created_at' => now()->subHours(2),
        ]);

        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);
        expect($payment->id)->not->toBe($stale->id);
    });
});

describe('PaymentService::handleWebhook() — happy path', function (): void {
    it('captures payment and transitions order to confirmed', function (): void {
        [$user, $order] = svcMakeUserOrder('card');
        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);

        $body = json_encode([
            'transaction_id' => $payment->gateway_transaction_id,
            'status' => 'success',
            'amount' => '525.00',
            'event_id' => 'evt_happy_1',
        ], JSON_THROW_ON_ERROR);

        $event = $this->service->handleWebhook($body, []);

        expect($event)->toBeInstanceOf(PaymentEvent::class)
            ->and($event->signature_valid)->toBeTrue();

        $payment->refresh();
        expect($payment->status)->toBe(PaymentStatus::Captured)
            ->and($payment->captured_at)->not->toBeNull();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);
    });
});

describe('PaymentService::handleWebhook() — idempotent replay', function (): void {
    it('records a replay event but does not re-mutate Payment/Order on a terminal status', function (): void {
        [$user, $order] = svcMakeUserOrder('card');
        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);

        $body = json_encode([
            'transaction_id' => $payment->gateway_transaction_id,
            'status' => 'success',
            'event_id' => 'evt_replay_1',
        ], JSON_THROW_ON_ERROR);

        $this->service->handleWebhook($body, []);
        $payment->refresh();
        $capturedAt = $payment->captured_at;

        // Replay.
        $this->service->handleWebhook($body, []);
        $payment->refresh();

        // Captured_at is NOT updated by the replay; the timestamp is sticky.
        expect($payment->captured_at?->toIso8601String())->toBe($capturedAt?->toIso8601String());

        // ONE pending→confirmed history row.
        expect(
            DB::table('order_status_history')
                ->where('order_id', $order->id)
                ->where('to_status', OrderStatus::Confirmed->value)
                ->count()
        )->toBe(1);
    });
});

describe('PaymentService::handleWebhook() — failure', function (): void {
    it('marks the payment failed without touching the order', function (): void {
        [$user, $order] = svcMakeUserOrder('card');
        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);

        $body = json_encode([
            'transaction_id' => $payment->gateway_transaction_id,
            'status' => 'failed',
            'failure_reason' => 'Issuer declined',
            'event_id' => 'evt_fail',
        ], JSON_THROW_ON_ERROR);

        $this->service->handleWebhook($body, []);

        $payment->refresh();
        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failed_at)->not->toBeNull()
            ->and($payment->failure_reason)->toBe('Issuer declined');

        // Order untouched.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Pending->value,
        ]);
    });
});

describe('PaymentService::handleWebhook() — orphan txn id', function (): void {
    it('writes a payment_events row with payment_id=null for an unknown gateway_transaction_id', function (): void {
        $body = json_encode([
            'transaction_id' => 'stub_unknown_'.uniqid(),
            'status' => 'success',
            'event_id' => 'evt_orphan',
        ], JSON_THROW_ON_ERROR);

        $event = $this->service->handleWebhook($body, []);

        expect($event->payment_id)->toBeNull()
            ->and($event->signature_valid)->toBeTrue();

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => null,
            'gateway_event_id' => 'evt_orphan',
        ]);
    });
});

describe('PaymentService::findOwned()', function (): void {
    it('returns the payment when the actor owns it', function (): void {
        [$user, $order] = svcMakeUserOrder('card');
        $payment = $this->service->initiate($user, $order, PaymentMethod::Card, null);

        $found = $this->service->findOwned($payment->id, $user);

        expect($found->id)->toBe($payment->id);
    });

    it('throws AuthorizationException when a stranger requests the payment', function (): void {
        [$owner, $order] = svcMakeUserOrder('card');
        $payment = $this->service->initiate($owner, $order, PaymentMethod::Card, null);
        $stranger = User::factory()->customer()->create();

        expect(fn () => $this->service->findOwned($payment->id, $stranger))
            ->toThrow(AuthorizationException::class);
    });

    it('throws ModelNotFoundException for an unknown payment id', function (): void {
        $user = User::factory()->customer()->create();

        expect(fn () => $this->service->findOwned(99999999, $user))
            ->toThrow(ModelNotFoundException::class);
    });
});
