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
| POST /api/v1/payments/create-session
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
function makeSessionCustomer(): array
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

function makeSessionOrder(User $user, Address $address, array $overrides = []): Order
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

describe('POST /api/v1/payments/create-session — happy path', function (): void {
    it('creates an initiated card payment and returns session data', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeSessionCustomer();
        $order = makeSessionOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'payment_id', 'public_id', 'order_id', 'status',
                    'payment_method', 'gateway', 'amount', 'currency',
                    'session_id', 'payment_reference', 'payment_token',
                    'created_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PaymentStatus::Initiated->value)
            ->assertJsonPath('data.payment_method', 'card')
            ->assertJsonPath('data.gateway', 'stub')
            ->assertJsonPath('data.amount', '1050.00')
            ->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.session_id', fn ($id) => is_string($id) && str_starts_with($id, 'stub_session_'))
            ->assertJsonPath('data.payment_reference', fn ($ref) => is_string($ref) && ! empty($ref));

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => PaymentStatus::Initiated->value,
            'gateway' => 'stub',
            'payment_method' => 'card',
            'amount' => '1050.00',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Re-initiation & retryable terminals
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/create-session — re-initiation guard', function (): void {
    it('rejects a second create-session while a non-expired initiated payment exists with 409', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeSessionCustomer();
        $order = makeSessionOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        // First call creates an `initiated` Payment.
        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ])->assertCreated();

        // Second call within TTL must be blocked.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_PAYMENT_ALREADY_INITIATED');
    });

    it('allows a new create-session when the previous payment is failed', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeSessionCustomer();
        $order = makeSessionOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        Payment::factory()->forOrder($order)->failed()->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::Initiated->value);

        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/create-session — idempotency', function (): void {
    it('replays the original session within the 24h window and returns 200 with the same payment_id', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeSessionCustomer();
        $order = makeSessionOrder($user, $address, [
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $key = 'idem-session-'.uniqid();

        $first = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);
        $first->assertCreated();
        $firstId = $first->json('data.payment_id');

        $second = $this->withHeader('Authorization', "Bearer $token")
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/payments/create-session', [
                'order_id' => $order->id,
                'payment_method' => 'card',
            ]);

        $second->assertOk()
            ->assertJsonPath('data.payment_id', $firstId)
            ->assertJsonPath('message', __('payments.replay_session'));

        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/payments/create-session — auth', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no token is provided', function (): void {
        $response = $this->postJson('/api/v1/payments/create-session', [
            'order_id' => 1,
            'payment_method' => 'card',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
