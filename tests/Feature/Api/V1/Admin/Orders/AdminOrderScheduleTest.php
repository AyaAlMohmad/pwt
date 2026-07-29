<?php

declare(strict_types=1);

use App\Enums\InstallationStatus;
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
| POST /api/v1/admin/orders/{id}/schedule-installation
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-orders.md § 3.4 / § 5.5.
| Requires source state = confirmed; transitions to scheduled AND
| inserts/updates the `order_installations` row in the same transaction.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function scheduleAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function confirmedOrder(): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    return Order::factory()->forUser($customer)->forAddress($address)
        ->state([
            'currency' => 'AED',
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ])
        ->create();
}

describe('POST /api/v1/admin/orders/{id}/schedule-installation', function (): void {
    it('schedules a confirmed order with valid datetime + operations technician', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $order = confirmedOrder();
        $technician = User::factory()->admin(UserRole::Operations->value)->create();
        $scheduledFor = now()->addDays(3)->setTime(10, 0);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => $scheduledFor->toIso8601String(),
                'technician_user_id' => $technician->id,
                'installation_notes' => 'Bring spare filter cartridge',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Scheduled->value);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Scheduled->value,
        ]);
        $this->assertDatabaseHas('order_installations', [
            'order_id' => $order->id,
            'technician_user_id' => $technician->id,
            'installation_notes' => 'Bring spare filter cartridge',
            'status' => InstallationStatus::Scheduled->value,
        ]);
    });

    it('returns 422 when technician_user_id is not an operations admin', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $order = confirmedOrder();
        $notTechnician = User::factory()->customer()->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => now()->addDays(2)->toIso8601String(),
                'technician_user_id' => $notTechnician->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['technician_user_id']]);

        $this->assertDatabaseMissing('order_installations', [
            'order_id' => $order->id,
        ]);
    });

    it('returns 422 when technician_user_id is a sales admin (wrong role)', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $order = confirmedOrder();
        $sales = User::factory()->admin(UserRole::Sales->value)->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => now()->addDays(2)->toIso8601String(),
                'technician_user_id' => $sales->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['technician_user_id']]);
    });

    it('returns 422 when scheduled_for_at is in the past', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $order = confirmedOrder();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['scheduled_for_at']]);
    });

    it('returns 422 when scheduled_for_at is missing', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $order = confirmedOrder();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['scheduled_for_at']]);
    });

    it('returns 409 when the order is not in confirmed state', function (): void {
        [, $bearer] = scheduleAdminBearer();
        $uae = $this->uae;
        $customer = User::factory()->customer()->create();
        $address = Address::factory()->forUser($customer)->forCountry($uae)->create();
        $order = Order::factory()->forUser($customer)->forAddress($address)->state([
            'status' => OrderStatus::Pending->value,
        ])->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_STATUS_TRANSITION');
    });

    it('returns 403 for sales role (no orders.schedule)', function (): void {
        [, $bearer] = scheduleAdminBearer(UserRole::Sales->value);
        $order = confirmedOrder();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
                'scheduled_for_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertStatus(403);
    });

    it('returns 404 for a non-existent order', function (): void {
        [, $bearer] = scheduleAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/orders/9999999/schedule-installation', [
                'scheduled_for_at' => now()->addDays(3)->toIso8601String(),
            ])
            ->assertStatus(404);
    });

    it('returns 401 without a token', function (): void {
        $order = confirmedOrder();

        $this->postJson("/api/v1/admin/orders/{$order->id}/schedule-installation", [
            'scheduled_for_at' => now()->addDays(3)->toIso8601String(),
        ])->assertStatus(401);
    });
});
