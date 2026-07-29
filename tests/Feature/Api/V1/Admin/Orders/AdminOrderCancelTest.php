<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| POST /api/v1/admin/orders/{id}/cancel
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-orders.md § 2.3 / § 3.3 / § 5.4.
| The implementation accepts: pending, confirmed, scheduled,
| out_for_delivery. installed/completed/cancelled → 409.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function cancelAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function cancelOrder(array $overrides): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    return Order::factory()->forUser($customer)->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('POST /api/v1/admin/orders/{id}/cancel — allowed source states', function (): void {
    it('cancels a pending order by admin → 200 with admin actor on status_history', function (): void {
        [$admin, $bearer] = cancelAdminBearer();
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Customer requested cancellation; alternate vendor chosen',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.cancellation_reason', 'Customer requested cancellation; alternate vendor chosen');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Cancelled->value,
            'cancelled_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Cancelled->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
        ]);
    });

    it('cancels a confirmed order by admin → 200', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Order cancelled by management decision',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    });

    it('cancels a scheduled order by admin → 200 (broader admin window)', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder([
            'status' => OrderStatus::Scheduled->value,
            'confirmed_at' => now()->subDay(),
            'scheduled_for_at' => now()->addDay(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Customer rescheduled but later withdrew completely',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    });

    it('cancels an out_for_delivery order by admin → 200 (per orchestrator decision 2)', function (): void {
        // Implementation: AdminOrderService::cancel() accepts source state
        // out_for_delivery. The architecture text mentions "up to installed"
        // but the shipped service stops at out_for_delivery — assert what
        // is actually implemented per the brief instructions.
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder(['status' => OrderStatus::OutForDelivery->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Customer rejected delivery at the door',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    });
});

describe('POST /api/v1/admin/orders/{id}/cancel — forbidden source states', function (): void {
    it('returns 409 ERR_INVALID_STATUS_TRANSITION when cancelling an installed order', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder([
            'status' => OrderStatus::Installed->value,
            'installed_at' => now()->subDay(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Attempt to cancel an installed order',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Installed->value,
        ]);
    });

    it('returns 409 when cancelling a completed order', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now()->subDay(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Attempt to cancel a completed order',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 409 when cancelling an already-cancelled order', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now()->subHour(),
            'cancellation_reason' => 'previous reason',
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Attempt to double-cancel',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });
});

describe('POST /api/v1/admin/orders/{id}/cancel — auth & validation', function (): void {
    it('returns 403 for sales role (no orders.cancel-any)', function (): void {
        [, $bearer] = cancelAdminBearer(UserRole::Sales->value);
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Sales attempting to cancel',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 for support role', function (): void {
        [, $bearer] = cancelAdminBearer(UserRole::Support->value);
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'Support attempting to cancel',
            ])
            ->assertStatus(403);
    });

    it('returns 422 when reason is missing', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    });

    it('returns 422 when reason is shorter than 5 chars', function (): void {
        [, $bearer] = cancelAdminBearer();
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
                'reason' => 'no',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    });

    it('returns 404 for a non-existent order id', function (): void {
        [, $bearer] = cancelAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/orders/9999999/cancel', [
                'reason' => 'Ghost order cancellation',
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 401 without a token', function (): void {
        $order = cancelOrder(['status' => OrderStatus::Pending->value]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel", [
            'reason' => 'Anon cancellation',
        ])->assertStatus(401);
    });
});
