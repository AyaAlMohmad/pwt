<?php

declare(strict_types=1);

use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/orders/{order}/tracking
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 3.5, § 4.5, § 5.5.
| Ownership-scoped; 403 on mismatch; 404 when missing; timeline ordered
| by occurred_at ASC; next_step null when status is terminal.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makeTrackingCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
    ]);

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

function persistTrackingOrder(User $user, Address $address, array $overrides = []): Order
{
    return Order::factory()->forUser($user)->forAddress($address)->state($overrides)->create();
}

describe('GET /api/v1/orders/{order}/tracking', function (): void {
    it('returns 200 with timeline + next_step + progress for a pending order', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeTrackingCustomer();
        $order = persistTrackingOrder($user, $address, [
            'status' => 'pending',
            'payment_method' => PaymentMethod::Cod->value,
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'customer',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}/tracking");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'order_id', 'public_id', 'status',
                    'tracking_progress', 'next_step', 'timeline',
                ],
            ])
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.tracking_progress', 10)
            ->assertJsonPath('data.next_step.status', 'confirmed');

        expect($response->json('data.timeline'))->toBeArray()->toHaveCount(1);
    });

    it('orders the timeline by occurred_at ASC', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeTrackingCustomer();
        $order = persistTrackingOrder($user, $address, [
            'status' => 'confirmed',
            'confirmed_at' => now()->subHours(2),
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'actor_type' => 'system',
            'created_at' => now()->subHours(2),
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'customer',
            'created_at' => now()->subHours(3),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}/tracking");

        $response->assertOk();
        $statuses = collect($response->json('data.timeline'))->pluck('to_status')->all();
        expect($statuses)->toBe(['pending', 'confirmed']);
    });

    it('returns next_step=null for a terminal status (cancelled) but still surfaces the timeline', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeTrackingCustomer();
        $order = persistTrackingOrder($user, $address, [
            'status' => 'cancelled',
            'cancelled_at' => now()->subHour(),
            'cancellation_reason' => 'Customer cancelled',
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null, 'to_status' => 'pending', 'actor_type' => 'customer',
            'created_at' => now()->subHours(3),
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => 'pending', 'to_status' => 'cancelled', 'actor_type' => 'customer',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}/tracking");

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.tracking_progress', 0)
            ->assertJsonPath('data.next_step', null);

        $toStatuses = collect($response->json('data.timeline'))->pluck('to_status')->all();
        expect($toStatuses)->toContain('cancelled');
    });

    it('returns 403 ERR_FORBIDDEN when tracking another user\'s order', function (): void {
        ['token' => $token] = makeTrackingCustomer();
        $other = User::factory()->customer()->create();
        $otherAddress = Address::factory()->forUser($other)->forCountry($this->uae)->create();
        $order = persistTrackingOrder($other, $otherAddress);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}/tracking");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 when the order does not exist', function (): void {
        ['token' => $token] = makeTrackingCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders/9999999/tracking');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a bearer token', function (): void {
        $user = User::factory()->customer()->create();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = persistTrackingOrder($user, $address);

        $response = $this->getJson("/api/v1/orders/{$order->id}/tracking");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
