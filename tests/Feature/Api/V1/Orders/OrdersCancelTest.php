<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/orders/{order}/cancel
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 3.4, § 4.1, § 5.4.
| Customer can cancel only from pending OR confirmed (CLAUDE.md § 7.3 +
| OrderStatus::isCustomerCancellable()). Anything else → 409
| ERR_INVALID_STATUS_TRANSITION. Ownership → 403. Not found → 404.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('orders.cancel');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makeCancelCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
    ]);

    RateLimiter::clear('orders.cancel:'.$user->id);

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

function persistCancelOrder(User $user, Address $address, array $overrides = []): Order
{
    return Order::factory()->forUser($user)->forAddress($address)->state($overrides)->create();
}

describe('POST /api/v1/orders/{order}/cancel — allowed transitions', function (): void {
    it('cancels a pending order and writes a status_history row', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, ['status' => 'pending']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Changed my mind about the purchase',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.cancellation_reason', 'Changed my mind about the purchase')
            ->assertJsonPath('data.is_cancellable', false);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'cancelled',
        ]);
    });

    it('cancels a confirmed order and surfaces the refund-note success message', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, [
            'status' => 'confirmed',
            'confirmed_at' => now()->subHour(),
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Bought elsewhere',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Confirmed orders carry the refund-note copy.
        expect((string) $response->json('message'))->toContain('refund');
    });
});

/*
|--------------------------------------------------------------------------
| Forbidden transitions → 409
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders/{order}/cancel — forbidden transitions', function (): void {
    it('returns 409 ERR_INVALID_STATUS_TRANSITION when the order is scheduled', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, [
            'status' => 'scheduled',
            'scheduled_for_at' => now()->addDays(3),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 when the order is out_for_delivery', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, ['status' => 'out_for_delivery']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 when the order is installed', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, [
            'status' => 'installed',
            'installed_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Try anyway',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 (with the "already cancelled" copy) when the order is already cancelled', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Old reason',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Try again',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });
});

/*
|--------------------------------------------------------------------------
| Auth, ownership, validation, rate limit
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders/{order}/cancel — auth & validation', function (): void {
    it('returns 403 ERR_FORBIDDEN when cancelling another user\'s order', function (): void {
        ['token' => $token] = makeCancelCustomer();
        $other = User::factory()->customer()->create();
        $otherAddress = Address::factory()->forUser($other)->forCountry($this->uae)->create();
        $order = persistCancelOrder($other, $otherAddress, ['status' => 'pending']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Not mine to cancel',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
    });

    it('returns 404 when the order id does not exist', function (): void {
        ['token' => $token] = makeCancelCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders/9999999/cancel', [
                'cancellation_reason' => 'Ghost order',
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 422 ERR_VALIDATION when cancellation_reason is missing', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, ['status' => 'pending']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", []);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    });

    it('returns 422 when cancellation_reason is under 5 chars', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();
        $order = persistCancelOrder($user, $address, ['status' => 'pending']);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'no',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        $user = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = persistCancelOrder($user, $addr, ['status' => 'pending']);

        $response = $this->postJson("/api/v1/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'no token',
        ]);

        $response->assertStatus(401);
    });

    it('returns 429 after exceeding the orders.cancel limiter (10/min)', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeCancelCustomer();

        // Pre-create 11 cancellable orders and attempt to cancel each.
        $orders = collect(range(1, 11))->map(
            fn () => persistCancelOrder($user, $address, ['status' => 'pending']),
        );

        $failed = 0;
        foreach ($orders as $order) {
            $resp = $this->withHeader('Authorization', "Bearer $token")
                ->postJson("/api/v1/orders/{$order->id}/cancel", [
                    'cancellation_reason' => 'limit test',
                ]);
            if ($resp->status() === 429) {
                $failed++;
            }
        }

        expect($failed)->toBeGreaterThan(0);
    });
});
