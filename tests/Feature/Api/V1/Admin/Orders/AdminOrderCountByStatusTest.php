<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/orders — counts by status embedded in index response
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('admin', ['*'])->plainTextToken;
});

describe('GET /api/v1/admin/orders — counts by status', function (): void {
    it('returns zero counts for all statuses when no orders exist', function (): void {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonPath('success', true);

        foreach (OrderStatus::cases() as $status) {
            $response->assertJsonPath("data.counts.{$status->value}", 0);
        }
    });

    it('returns correct counts grouped by status alongside paginated orders', function (): void {
        $address = Address::factory()->forUser($this->admin)->forCountry($this->uae)->create();

        // Create 3 pending, 2 confirmed, 1 cancelled
        Order::factory()->count(3)->forUser($this->admin)->forAddress($address)->state([
            'status' => OrderStatus::Pending->value,
        ])->create();

        Order::factory()->count(2)->forUser($this->admin)->forAddress($address)->state([
            'status' => OrderStatus::Confirmed->value,
        ])->create();

        Order::factory()->count(1)->forUser($this->admin)->forAddress($address)->state([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
        ])->create();

        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.counts.pending', 3)
            ->assertJsonPath('data.counts.confirmed', 2)
            ->assertJsonPath('data.counts.cancelled', 1)
            ->assertJsonPath('data.counts.scheduled', 0)
            ->assertJsonPath('data.counts.out_for_delivery', 0)
            ->assertJsonPath('data.counts.installed', 0)
            ->assertJsonPath('data.counts.completed', 0)
            ->assertJsonStructure(['data.orders'])
            ->assertJsonPath('meta.pagination.total', 6);
    });
});
