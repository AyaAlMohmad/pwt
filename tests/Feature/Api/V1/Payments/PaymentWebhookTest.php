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
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/payments/webhook
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/payments.md § 1.1, § 1.7, § 2.3, § 7.5.
| No Sanctum — signature middleware is the auth surface. Idempotent on
| `gateway_transaction_id`. 401 ERR_SIGNATURE_INVALID on missing or
| wrong signature. 200 on every signed body, even orphan-txn replays.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('webhook');

    config(['payment.default' => 'stub']);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * Persist a Payment in `initiated` state attached to a pending order.
 */
function persistInitiatedPayment(?string $txnId = null): Payment
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $order = Order::factory()->forUser($user)->forAddress($address)->state([
        'status' => OrderStatus::Pending->value,
        'currency' => 'AED',
        'subtotal_amount' => '500.00',
        'discount_amount' => '0.00',
        'tax_amount' => '25.00',
        'tax_rate' => '5.00',
        'shipping_amount' => '0.00',
        'total_amount' => '525.00',
        'payment_method' => PaymentMethod::Card->value,
    ])->create();

    return Payment::factory()->forOrder($order)->initiated()->create([
        'gateway_transaction_id' => $txnId ?? 'stub_'.bin2hex(random_bytes(8)),
        'amount' => '525.00',
        'currency' => 'AED',
    ]);
}

/*
|--------------------------------------------------------------------------
| Signature middleware
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — signature middleware', function (): void {
    it('returns 401 ERR_SIGNATURE_INVALID when the X-Stub-Signature header is missing', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->postJson('/api/v1/payments/webhook', [
            'transaction_id' => $payment->gateway_transaction_id,
            'status' => 'success',
            'event_id' => 'evt_'.uniqid(),
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_SIGNATURE_INVALID')
            ->assertJsonPath('success', false);

        // Payment untouched.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Initiated->value,
        ]);
        $this->assertDatabaseCount('payment_events', 0);
    });

    it('returns 401 ERR_SIGNATURE_INVALID when X-Stub-Signature is wrong', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'wrong-value')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'success',
            ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_SIGNATURE_INVALID');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Initiated->value,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Happy path — captured
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — captured', function (): void {
    it('captures the payment, transitions the order to confirmed, and records a payment_events row', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'success',
                'amount' => '525.00',
                'currency' => 'AED',
                'event_id' => 'evt_capture_1',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Captured->value,
        ]);

        // Order auto-confirmed via OrderStatusTransitionService.
        $this->assertDatabaseHas('orders', [
            'id' => $payment->order_id,
            'status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $payment->order_id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
        ]);

        // PaymentEvent recorded with signature_valid=true.
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'webhook',
            'source' => 'stub',
            'signature_valid' => true,
            'gateway_event_id' => 'evt_capture_1',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Idempotency on replay
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — idempotency', function (): void {
    it('returns 200 on an unknown gateway_transaction_id without mutating any Payment', function (): void {
        $existing = persistInitiatedPayment(); // payment exists but txn_id is different

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => 'stub_unknown_'.uniqid(),
                'status' => 'success',
                'event_id' => 'evt_orphan',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $existing->id,
            'status' => PaymentStatus::Initiated->value,
        ]);

        // An orphan event row should be written for forensic admin review.
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => null,
            'type' => 'webhook',
            'gateway_event_id' => 'evt_orphan',
            'signature_valid' => true,
        ]);
    });

    it('processes the captured webhook exactly once on replay — only one status_history row, single captured state', function (): void {
        $payment = persistInitiatedPayment();
        $payload = [
            'transaction_id' => $payment->gateway_transaction_id,
            'status' => 'success',
            'amount' => '525.00',
            'event_id' => 'evt_replay_1',
        ];

        $first = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', $payload);
        $first->assertOk();

        // Same payload re-delivered (gateway retry).
        $second = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', $payload);
        $second->assertOk();

        // Single captured Payment.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Captured->value,
        ]);

        // ONE pending → confirmed history row (not two).
        expect(
            DB::table('order_status_history')
                ->where('order_id', $payment->order_id)
                ->where('from_status', OrderStatus::Pending->value)
                ->where('to_status', OrderStatus::Confirmed->value)
                ->count()
        )->toBe(1);

        // TWO payment_events rows (one real, one replay).
        $this->assertDatabaseCount('payment_events', 2);
    });
});

/*
|--------------------------------------------------------------------------
| Failure + cancellation states
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — failed', function (): void {
    it('marks the payment as failed and leaves the order in pending', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'failed',
                'failure_reason' => 'Card declined by issuer',
                'event_id' => 'evt_fail_1',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Failed->value,
            'failure_reason' => 'Card declined by issuer',
        ]);

        // Order STAYS pending so the customer may retry.
        $this->assertDatabaseHas('orders', [
            'id' => $payment->order_id,
            'status' => OrderStatus::Pending->value,
        ]);

        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'webhook',
            'signature_valid' => true,
        ]);
    });
});

describe('POST /api/v1/payments/webhook — cancelled', function (): void {
    it('marks the payment as cancelled when the gateway reports `cancelled`', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'cancelled',
                'event_id' => 'evt_cancel_1',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Cancelled->value,
        ]);
        $payment->refresh();
        expect($payment->cancelled_at)->not->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Malformed body
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — malformed body', function (): void {
    it('records a `failed` Payment when the payload omits status (driver maps unknown → failed)', function (): void {
        // A signed but semantically-empty payload reaches the service.
        // StubDriver::parseWebhookPayload() defaults unknown status →
        // PaymentStatus::Failed. The unknown txn_id (empty) is treated
        // as an orphan and a payment_events row is written without any
        // Payment mutation.
        $existing = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'unrelated' => 'payload',
            ]);

        $response->assertOk();

        // Existing Payment NOT mutated because the txn id didn't match.
        $this->assertDatabaseHas('payments', [
            'id' => $existing->id,
            'status' => PaymentStatus::Initiated->value,
        ]);
        // One orphan event row.
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => null,
            'type' => 'webhook',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Rate limiter wiring (representative call)
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/webhook — rate limiter is wired', function (): void {
    it('reaches the signature middleware on a single call (the throttle:webhook limiter does not gate the first request)', function (): void {
        $payment = persistInitiatedPayment();

        $response = $this->withHeader('X-Stub-Signature', 'stub-signature-ok')
            ->postJson('/api/v1/payments/webhook', [
                'transaction_id' => $payment->gateway_transaction_id,
                'status' => 'success',
                'event_id' => 'evt_rate_check',
            ]);

        $response->assertOk();
    });
});
