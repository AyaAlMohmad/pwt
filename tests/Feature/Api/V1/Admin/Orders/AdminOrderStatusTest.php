<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductTerm;
use App\Enums\UserRole;
use App\Events\Orders\OrderConfirmed;
use App\Events\Orders\OrderInstalled;
use App\Events\Orders\OrderOutForDelivery;
use App\Models\Address;
use App\Models\Country;
use App\Models\Machine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| PATCH /api/v1/admin/orders/{id}/status
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-orders.md § 2.2 / § 3.2 / § 5.3.
| Drives the state machine through `AdminOrderService::transitionStatus`
| which delegates to `OrderStatusTransitionService`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function statusAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function statusOrder(array $overrides = []): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create([
        'is_default' => true,
    ]);

    return Order::factory()->forUser($customer)->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('PATCH /api/v1/admin/orders/{id}/status', function (): void {
    it('transitions pending → confirmed by operations and records an admin actor on status_history', function (): void {
        [$admin, $bearer] = statusAdminBearer(UserRole::Operations->value);
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
                'note' => 'COD payment collected on doorstep',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', OrderStatus::Confirmed->value);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'note' => 'COD payment collected on doorstep',
        ]);
    });

    it('returns 409 ERR_USE_SCHEDULE_ENDPOINT when status=scheduled is supplied', function (): void {
        [, $bearer] = statusAdminBearer(UserRole::Operations->value);
        $order = statusOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Scheduled->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_USE_SCHEDULE_ENDPOINT');

        // Order must NOT have moved.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);
    });

    it('returns 409 ERR_INVALID_STATUS_TRANSITION for pending → installed', function (): void {
        [, $bearer] = statusAdminBearer();
        $order = statusOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Installed->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 ERR_PAYMENT_NOT_CAPTURED when admin confirms an online order without captured payment', function (): void {
        [, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Card->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_PAYMENT_NOT_CAPTURED');
    });

    it('allows pending → confirmed for an online order when payment is already captured', function (): void {
        [, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Card->value,
        ]);

        Payment::factory()->forOrder($order)->create([
            'status' => PaymentStatus::Captured->value,
            'gateway' => 'payfort',
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Confirmed->value);
    });

    it('returns 403 for sales role (no orders.update-status)', function (): void {
        [, $bearer] = statusAdminBearer(UserRole::Sales->value);
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for support role (read-only, no orders.update-status)', function (): void {
        [, $bearer] = statusAdminBearer(UserRole::Support->value);
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertStatus(403);
    });

    it('transitions out_for_delivery → installed and auto-creates Machine rows', function (): void {
        [, $bearer] = statusAdminBearer(UserRole::Operations->value);
        $order = statusOrder([
            'status' => OrderStatus::OutForDelivery->value,
            'confirmed_at' => now()->subDays(3),
            'scheduled_for_at' => now()->subDay(),
        ]);

        // A machine-eligible product on the order line — the Phase 10
        // service uses this flag to decide whether to create the Machine.
        $product = Product::factory()->create(['is_machine_eligible' => true]);
        OrderItem::factory()->forOrder($order)->forProduct($product)
            ->withUnitPrice(1500.00, 1)
            ->state(['term' => ProductTerm::Buy->value])
            ->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Installed->value,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Installed->value);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Installed->value,
        ]);
        expect(Machine::query()->where('order_id', $order->id)->count())->toBe(1);
    });

    it('captures an inline note on the status_history row', function (): void {
        [$admin, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
                'note' => 'Customer paid in cash — receipt #12345 issued',
            ])
            ->assertOk();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_id' => $admin->id,
            'note' => 'Customer paid in cash — receipt #12345 issued',
        ]);
    });

    it('dispatches the OrderConfirmed event on pending → confirmed', function (): void {
        Event::fake([OrderConfirmed::class]);
        [, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::Pending->value,
            'payment_method' => PaymentMethod::Cod->value,
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertOk();

        Event::assertDispatched(
            OrderConfirmed::class,
            fn (OrderConfirmed $e): bool => $e->orderId === $order->id,
        );
    });

    it('dispatches OrderInstalled on out_for_delivery → installed', function (): void {
        Event::fake([OrderInstalled::class]);
        [, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::OutForDelivery->value,
            'confirmed_at' => now()->subDay(),
        ]);
        $product = Product::factory()->create(['is_machine_eligible' => false]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->withUnitPrice(500.00, 1)->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Installed->value,
            ])
            ->assertOk();

        Event::assertDispatched(
            OrderInstalled::class,
            fn (OrderInstalled $e): bool => $e->orderId === $order->id,
        );
    });

    it('dispatches OrderOutForDelivery on scheduled → out_for_delivery', function (): void {
        Event::fake([OrderOutForDelivery::class]);
        [, $bearer] = statusAdminBearer();
        $order = statusOrder([
            'status' => OrderStatus::Scheduled->value,
            'confirmed_at' => now()->subDay(),
            'scheduled_for_at' => now()->addDay(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::OutForDelivery->value,
            ])
            ->assertOk();

        Event::assertDispatched(OrderOutForDelivery::class);
    });

    it('returns 422 when the body status is invalid', function (): void {
        [, $bearer] = statusAdminBearer();
        $order = statusOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => 'not-a-status',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    });

    it('returns 422 when a forbidden money field is supplied', function (): void {
        [, $bearer] = statusAdminBearer();
        $order = statusOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => OrderStatus::Confirmed->value,
                'total_amount' => '999.99',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['total_amount']]);
    });

    it('returns 404 for a non-existent order id', function (): void {
        [, $bearer] = statusAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/orders/9999999/status', [
                'status' => OrderStatus::Confirmed->value,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a token', function (): void {
        $order = statusOrder(['status' => OrderStatus::Pending->value]);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => OrderStatus::Confirmed->value,
        ])->assertStatus(401);
    });
});
