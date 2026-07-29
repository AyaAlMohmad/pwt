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
| POST /api/v1/admin/orders/{id}/notes
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-orders.md § 3.5 / § 4 / § 5.6.
| Writes a notes-only `order_status_history` row with is_internal=true.
| 409 ERR_INVALID_ORDER_STATE on terminal orders.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{0: User, 1: string}
 */
function notesAdminBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

function notesOrder(array $overrides = []): Order
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $customer = User::factory()->customer()->create();
    $address = Address::factory()->forUser($customer)->forCountry($uae)->create();

    return Order::factory()->forUser($customer)->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('POST /api/v1/admin/orders/{id}/notes', function (): void {
    it('writes an internal notes-only row with is_internal=true (admin)', function (): void {
        [$admin, $bearer] = notesAdminBearer();
        $order = notesOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Spoke with customer; they will be home Friday between 10am-2pm',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'from_status', 'to_status',
                    'actor_type', 'actor_id', 'actor_name',
                    'note', 'is_internal', 'is_notes_only',
                    'occurred_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.from_status', OrderStatus::Confirmed->value)
            ->assertJsonPath('data.to_status', OrderStatus::Confirmed->value)
            ->assertJsonPath('data.actor_type', OrderActorType::Admin->value)
            ->assertJsonPath('data.actor_id', $admin->id)
            ->assertJsonPath('data.is_internal', true)
            ->assertJsonPath('data.is_notes_only', true);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => OrderStatus::Confirmed->value,
            'to_status' => OrderStatus::Confirmed->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'note' => 'Spoke with customer; they will be home Friday between 10am-2pm',
            'is_internal' => true,
        ]);

        // Status itself unchanged.
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Confirmed->value,
        ]);
    });

    it('returns 409 ERR_INVALID_ORDER_STATE on a completed order', function (): void {
        [, $bearer] = notesAdminBearer();
        $order = notesOrder([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now()->subDay(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Attempt to add a note on a terminal order',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_ORDER_STATE');
    });

    it('returns 409 ERR_INVALID_ORDER_STATE on a cancelled order', function (): void {
        [, $bearer] = notesAdminBearer();
        $order = notesOrder([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now()->subHour(),
            'cancellation_reason' => 'Customer request',
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Attempt to add a note on a cancelled order',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERR_INVALID_ORDER_STATE');
    });

    it('returns 422 when note is missing', function (): void {
        [, $bearer] = notesAdminBearer();
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);
    });

    it('returns 422 when note is shorter than 3 chars', function (): void {
        [, $bearer] = notesAdminBearer();
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'no',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);
    });

    it('returns 422 when note exceeds the 2000-char standalone cap', function (): void {
        [, $bearer] = notesAdminBearer();
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => str_repeat('A', 2001),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['note']]);
    });

    it('allows operations role to add notes (orders.update-status)', function (): void {
        [, $bearer] = notesAdminBearer(UserRole::Operations->value);
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Operations note on order',
            ])
            ->assertStatus(201);
    });

    it('returns 403 for sales role (no orders.update-status)', function (): void {
        [, $bearer] = notesAdminBearer(UserRole::Sales->value);
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Sales attempt to add note',
            ])
            ->assertStatus(403);
    });

    it('returns 403 for support role (read-only; no orders.update-status)', function (): void {
        [, $bearer] = notesAdminBearer(UserRole::Support->value);
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Support attempt to add note',
            ])
            ->assertStatus(403);
    });

    it('returns 404 for a non-existent order', function (): void {
        [, $bearer] = notesAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/orders/9999999/notes', [
                'note' => 'Ghost note',
            ])
            ->assertStatus(404);
    });

    it('returns 401 without a token', function (): void {
        $order = notesOrder(['status' => OrderStatus::Pending->value]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/notes", [
            'note' => 'Anon note attempt',
        ])->assertStatus(401);
    });

    it('persists the internal note with is_internal=true on the order_status_history row', function (): void {
        // The customer-side leak check is covered end-to-end by the
        // dedicated regression test `CustomerInternalNoteFilteringTest`.
        // Here we lock down the underlying DB state.
        [$admin, $bearer] = notesAdminBearer();
        $order = notesOrder([
            'status' => OrderStatus::Confirmed->value,
            'confirmed_at' => now()->subHour(),
        ]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/orders/{$order->id}/notes", [
                'note' => 'Sensitive internal note - do not leak',
            ])->assertStatus(201);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'actor_id' => $admin->id,
            'note' => 'Sensitive internal note - do not leak',
            'is_internal' => true,
        ]);
    });
});
