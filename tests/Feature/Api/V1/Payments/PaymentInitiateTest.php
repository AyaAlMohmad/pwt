<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/payments/initiate
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/payments.md § 1.1, § 2, § 3.1, § 4.2.
| Auth: Sanctum. Customer-owned order in `pending`. Server recomputes
| `amount` from order.total_amount (never trusts the client).
| Rate-limited at 5/min keyed on user+IP via `payments.initiate`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('payments.initiate');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();

    // Default stub gateway in tests.
    config(['payment.default' => 'stub']);
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makePayCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();

    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
    ]);

    RateLimiter::clear('payments.initiate:'.$user->id.':127.0.0.1');

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

function makePayableOrder(User $user, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($user)
        ->forAddress($address)
        ->state(array_merge([
            'status' => OrderStatus::Pending->value,
            'currency' => 'AED',
            'subtotal_amount' => '1000.00',
            'discount_amount' => '0.00',
            'tax_amount' => '50.00',
            'tax_rate' => '5.00',
            'shipping_amount' => '0.00',
            'total_amount' => '1050.00',
            'payment_method' => PaymentMethod::Card->value,
        ], $overrides))
        ->create();
}

/*
|--------------------------------------------------------------------------
| Happy paths
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — COD happy path', function (): void {
    it('creates a captured COD payment and auto-confirms the order', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'cod',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'payment_id', 'public_id', 'order_id', 'status',
                    'payment_method', 'gateway', 'amount', 'currency',
                    'payment_url', 'expires_at', 'captured_at',
                    'created_at', 'order',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PaymentStatus::Captured->value)
            ->assertJsonPath('data.payment_method', PaymentMethod::Cod->value)
            ->assertJsonPath('data.gateway', 'cod')
            ->assertJsonPath('data.payment_url', null)
            ->assertJsonPath('data.amount', '1050.00')
            ->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.order.status', OrderStatus::Confirmed->value);

        // Message hints at next admin confirmation step.
        expect($response->json('message'))->toBe(__('payments.cod_confirmed'));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Captured->value,
            'gateway' => 'cod',
            'payment_method' => PaymentMethod::Cod->value,
            'amount' => '1050.00',
        ]);

        // Order auto-transitioned to confirmed inside the same transaction.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
        ]);
    });

    it('does not leak gateway_transaction_id on the COD response', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'cod',
            ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.gateway_transaction_id')
            ->assertJsonMissingPath('data.gateway_reference')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.user_id');
    });
});

describe('POST /api/v1/payments/initiate — online happy path', function (): void {
    it('creates an initiated card payment and returns embedded-checkout session fields without leaking the gateway txn id', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PaymentStatus::Initiated->value)
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.gateway', 'stub')
            ->assertJsonPath('data.amount', '1050.00')
            ->assertJsonMissingPath('data.gateway_transaction_id')
            ->assertJsonPath('data.session_id', fn ($id) => is_string($id) && ! empty($id))
            ->assertJsonPath('data.payment_reference', fn ($ref) => is_string($ref) && ! empty($ref))
            ->assertJsonPath('data.payment_token', fn ($tok) => is_string($tok) && ! empty($tok));

        // payment_url is null for embedded-checkout flows.
        $response->assertJsonPath('data.payment_url', null);

        // Order STAYS in pending until the webhook fires.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Pending->value,
        ]);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => PaymentStatus::Initiated->value,
            'gateway' => 'stub',
            'payment_method' => 'card',
        ]);

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        expect($payment->gateway_transaction_id)->toStartWith('stub_');
    });
});

/*
|--------------------------------------------------------------------------
| Re-initiation & retryable terminals
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — re-initiation guard', function (): void {
    it('rejects a second initiate while a non-expired initiated payment exists with 409 ERR_PAYMENT_ALREADY_INITIATED', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        // First call creates an `initiated` Payment.
        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ])->assertCreated();

        // Second call within TTL must be blocked.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_PAYMENT_ALREADY_INITIATED');
    });

    it('allows a new initiate when the previous payment is `failed` (retryable terminal)', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        // Pre-existing failed Payment must NOT block re-initiation.
        Payment::factory()->forOrder($order)->failed()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::Initiated->value);

        // Two payment rows for this order now.
        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Order state / ownership guards
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — order state guards', function (): void {
    it('returns 422 when the order belongs to a different customer (owned-order rule fires)', function (): void {
        $owner = User::factory()->customer()->create();
        $ownerAddress = Address::factory()->forUser($owner)->forCountry($this->uae)->create();
        $order = makePayableOrder($owner, $ownerAddress, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        ['token' => $token] = makePayCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['order_id']]);
    });

    it('returns 422 ERR_ORDER_NOT_PAYABLE when the order is cancelled', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now()->subMinute(),
            'payment_method' => PaymentMethod::Card->value,
        ]);

        // The validator-level owned-order rule excludes soft-deleted only;
        // a cancelled order still passes ownership. Service-layer guard
        // surfaces 422 ERR_ORDER_NOT_PAYABLE via OrderNotPayableException.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_ORDER_NOT_PAYABLE');
    });

    it('returns 422 ERR_ORDER_NOT_PAYABLE when the order is already confirmed (already paid)', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subMinute(),
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_ORDER_NOT_PAYABLE');
    });
});

/*
|--------------------------------------------------------------------------
| Idempotency-Key replay
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — idempotency', function (): void {
    it('replays the original card payment within the 24h window and returns 200 with the same payment_id', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        // Use a card (online) order — COD auto-confirms the order and
        // would invalidate the second call via OrderNotPayable BEFORE
        // the idempotency lookup even runs.
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $key = 'idem-test-'.uniqid();

        $first = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);
        $first->assertCreated();
        $firstId = $first->json('data.payment_id');

        $second = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $second->assertOk() // 200 on replay (not 201).
            ->assertJsonPath('data.payment_id', $firstId)
            ->assertJsonPath('message', __('payments.replay_initiated'));

        // Single payment row was created.
        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
    });

    it('returns 422 ERR_VALIDATION when Idempotency-Key violates the 8–80 char regex', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        // Contains a space — regex ^[A-Za-z0-9_\-]{8,80}$ rejects this.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Idempotency-Key', 'has space xx')
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'cod',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['Idempotency-Key']]);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — validation', function (): void {
    it('returns 422 when payment_method is not one of the enum values', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'bitcoin',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['payment_method']]);
    });

    it('returns 422 when order_id is missing', function (): void {
        ['token' => $token] = makePayCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'payment_method' => 'card',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['order_id']]);
    });

    it('rejects forbidden server-managed fields (amount, currency, status, gateway_transaction_id) with 422', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
            'total_amount' => '1050.00',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'card',
                'amount' => '1.00',          // forbidden
                'currency' => 'USD',         // forbidden
                'status' => 'captured',      // forbidden
                'gateway_transaction_id' => 'fake', // forbidden
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure([
                'errors' => ['amount', 'currency', 'status', 'gateway_transaction_id'],
            ]);
    });
});

/*
|--------------------------------------------------------------------------
| Auth + ownership
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — auth', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no token is provided', function (): void {
        $response = $this->postJson('/api/v1/payments/initiate', [
            'order_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| Server-side amount recomputation
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — server recomputes amount', function (): void {
    it('persists Payment.amount equal to Order.total_amount regardless of client-supplied amount', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();
        $order = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Cod->value,
            'total_amount' => '777.77',
        ]);

        // A malicious client tries to under-pay by sending `amount=1.00`.
        // The Form Request prohibits the `amount` field, but even if we
        // bypass via a payload that doesn't carry it, the persisted
        // Payment.amount MUST be the order total.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $order->id,
                'payment_method' => 'cod',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '777.77');

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        expect((string) $payment->amount)->toBe('777.77');
    });
});

/*
|--------------------------------------------------------------------------
| Rate limit
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/initiate — rate limit', function (): void {
    it('returns 429 ERR_RATE_LIMITED after the 5/min cap', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makePayCustomer();

        // Fire 5 successful initiates against distinct orders so each
        // one passes service-level guards but counts against the
        // shared per-user+IP limiter.
        for ($i = 0; $i < 5; $i++) {
            $o = makePayableOrder($user, $address, [
                'payment_method' => PaymentMethod::Card->value,
            ]);
            $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/payments/initiate', [
                    'order_id' => $o->id,
                    'payment_method' => 'card',
                ])->assertCreated();
        }

        // 6th call should be throttled.
        $sixth = makePayableOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/initiate', [
                'order_id' => $sixth->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(429)
            ->assertJsonPath('code', 'ERR_RATE_LIMITED');
    });
});
