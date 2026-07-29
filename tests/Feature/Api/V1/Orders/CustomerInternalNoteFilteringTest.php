<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Phase 17 — customer-side filtering of internal admin notes
|--------------------------------------------------------------------------
|
| Regression for docs/architecture/admin-orders.md § 9.4 and the
| Phase 8 `OrderDetailResource` / `OrderTrackingResource` filter.
|
| The admin /notes endpoint writes a row with is_internal=true. The
| customer-facing detail and tracking projections MUST exclude it.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function makeFilteringCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create(['is_default' => true]);

    return [$user, $token, $address];
}

function filteringOrder(User $owner, Address $address): Order
{
    return Order::factory()->forUser($owner)->forAddress($address)
        ->state([
            'currency' => 'AED',
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ])
        ->create();
}

describe('Customer-side filtering of internal admin notes', function (): void {
    it('GET /api/v1/orders/{id} excludes status_history rows where is_internal=true', function (): void {
        [$customer, $token, $address] = makeFilteringCustomer();
        $admin = User::factory()->admin()->create();
        $order = filteringOrder($customer, $address);

        // Visible transition row.
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => $customer->id,
            'note' => 'Order placed',
            'is_internal' => false,
        ]);

        // Internal admin note row that must be filtered out.
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'note' => 'Internal note — must not leak to customer',
            'is_internal' => true,
        ]);

        // Visible transition row written after the internal note.
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_type' => OrderActorType::System->value,
            'note' => 'Payment captured',
            'is_internal' => false,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk();

        $history = (array) $response->json('data.status_history');
        $notes = array_column($history, 'note');

        expect($history)->toHaveCount(2);
        expect($notes)->not->toContain('Internal note — must not leak to customer');
        expect($notes)->toContain('Order placed');
        expect($notes)->toContain('Payment captured');
    });

    it('GET /api/v1/orders/{id}/tracking excludes internal admin notes', function (): void {
        [$customer, $token, $address] = makeFilteringCustomer();
        $admin = User::factory()->admin()->create();
        $order = filteringOrder($customer, $address);

        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => $customer->id,
            'created_at' => now()->subHours(2),
            'is_internal' => false,
        ]);
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'note' => 'INTERNAL TRACKING NOTE',
            'is_internal' => true,
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}/tracking")
            ->assertOk();

        $timeline = (array) $response->json('data.timeline');

        expect($timeline)->toHaveCount(1);
        $notes = array_filter(array_column($timeline, 'note'));
        expect($notes)->not->toContain('INTERNAL TRACKING NOTE');
    });
});
