<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/me/addresses/{id} — ERR_ADDRESS_IN_USE guard
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/addresses.md § 9.6 and
| docs/architecture/orders.md § 9.8. The AddressService::isAddressInUse()
| stub was wired in Phase 8 — addresses that are referenced by an order
| in (pending, confirmed, scheduled, out_for_delivery) status MUST NOT be
| deletable, and surface 409 ERR_ADDRESS_IN_USE.
|
| Orders in cancelled or completed status are historical and should NOT
| block deletion.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string}
 */
function makeAddressInUseCustomer(): array
{
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;

    return ['user' => $user, 'token' => $token];
}

/**
 * Persist a synthetic order referencing $address for $user. Caller
 * controls the status via $overrides['status'].
 */
function persistOrderForAddress(User $user, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($user)
        ->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

describe('DELETE /api/v1/me/addresses/{id} — ERR_ADDRESS_IN_USE guard', function (): void {
    foreach (['pending', 'confirmed', 'scheduled', 'out_for_delivery'] as $activeStatus) {
        it("returns 409 ERR_ADDRESS_IN_USE when an order in status=$activeStatus uses the address", function () use ($activeStatus): void {
            ['user' => $user, 'token' => $token] = makeAddressInUseCustomer();
            $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
            persistOrderForAddress($user, $address, ['status' => $activeStatus]);

            $response = $this->withHeader('Authorization', "Bearer $token")
                ->deleteJson("/api/v1/me/addresses/{$address->id}");

            $response->assertStatus(409)
                ->assertJsonPath('code', 'ERR_ADDRESS_IN_USE');

            // Address remains active (not soft-deleted).
            $this->assertDatabaseHas('addresses', [
                'id' => $address->id,
                'deleted_at' => null,
            ]);
        });
    }

    it('allows DELETE when the address is only referenced by a cancelled order', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressInUseCustomer();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        persistOrderForAddress($user, $address, [
            'status' => 'cancelled',
            'cancelled_at' => now()->subHour(),
            'cancellation_reason' => 'historical',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $address->id);

        $this->assertSoftDeleted('addresses', ['id' => $address->id]);
    });

    it('allows DELETE when the address is only referenced by a completed order', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressInUseCustomer();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        persistOrderForAddress($user, $address, [
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertOk();
        $this->assertSoftDeleted('addresses', ['id' => $address->id]);
    });

    it('allows DELETE when no order references the address', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressInUseCustomer();
        $address = Address::factory()->forUser($user)->forCountry($this->uae)->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$address->id}");

        $response->assertOk();
        $this->assertSoftDeleted('addresses', ['id' => $address->id]);
    });

    it('also blocks DELETE when the address is referenced via billing_address_id on an active order', function (): void {
        ['user' => $user, 'token' => $token] = makeAddressInUseCustomer();
        $deliveryAddress = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $billingAddress = Address::factory()->forUser($user)->forCountry($this->uae)->create();

        Order::factory()
            ->forUser($user)
            ->forAddress($deliveryAddress)
            ->state([
                'billing_address_id' => $billingAddress->id,
                'status' => 'pending',
                'currency' => 'AED',
            ])
            ->create();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/v1/me/addresses/{$billingAddress->id}");

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ERR_ADDRESS_IN_USE');
    });
});
