<?php

declare(strict_types=1);

use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| GET /api/v1/orders + GET /api/v1/orders/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 3.2, § 3.3, § 4.1, § 4.2,
| § 5.2, § 5.3. Caller-scoped reads — ownership returns 403 (not 404)
| per CLAUDE.md § 7.3.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    RateLimiter::clear('orders.create');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makeListCustomer(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create([
        'is_default' => true,
        'city' => 'Dubai',
    ]);

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

/**
 * Persist a synthetic order (no real cart) so the list / show tests
 * don't need the full create flow.
 */
function persistOrder(User $user, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($user)
        ->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/orders
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/orders', function (): void {
    it('returns 200 with an empty data array and pagination meta when no orders exist', function (): void {
        ['token' => $token] = makeListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'data',
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to']],
            ])
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('returns the caller\'s orders sorted created_at DESC + pagination meta', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeListCustomer();
        $older = persistOrder($user, $address, ['created_at' => now()->subDays(3)]);
        $newer = persistOrder($user, $address, ['created_at' => now()->subDay()]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newer->id, $older->id]);
    });

    it('excludes other users\' orders', function (): void {
        ['token' => $token, 'address' => $address] = makeListCustomer();
        $stranger = User::factory()->customer()->create();
        $strangerAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        persistOrder($stranger, $strangerAddress);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 0);
    });

    it('filters by status=pending', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeListCustomer();
        persistOrder($user, $address, ['status' => 'pending']);
        persistOrder($user, $address, ['status' => 'cancelled', 'cancelled_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders?status=pending');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'pending');
    });

    it('filters by date_from / date_to', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeListCustomer();
        persistOrder($user, $address, ['created_at' => now()->subDays(10)]);
        $recent = persistOrder($user, $address, ['created_at' => now()->subDay()]);

        $from = now()->subDays(5)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders?date_from=$from&date_to=$to");

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $recent->id);
    });

    it('returns 422 ERR_VALIDATION when per_page exceeds 100', function (): void {
        ['token' => $token] = makeListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders?per_page=500');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('returns 422 when date_to < date_from', function (): void {
        ['token' => $token] = makeListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders?date_from=2026-02-01&date_to=2026-01-01');

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_to']]);
    });

    it('returns 401 ERR_UNAUTHENTICATED without a bearer token', function (): void {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/orders/{id}
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/orders/{id}', function (): void {
    it('returns the OrderDetailResource shape for the owner', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeListCustomer();
        $order = persistOrder($user, $address);
        OrderItem::factory()->forOrder($order)->withUnitPrice(500.00, 2)->create();
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'customer',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'status', 'items', 'status_history',
                    'delivery_address', 'billing_address', 'promotion',
                    'notes', 'payment',
                ],
            ])
            ->assertJsonPath('data.id', $order->id);

        expect($response->json('data.items'))->toHaveCount(1);
        expect($response->json('data.status_history'))->toHaveCount(1);
    });

    it('returns 403 ERR_FORBIDDEN when the order belongs to another user', function (): void {
        ['token' => $token] = makeListCustomer();
        $other = User::factory()->customer()->create();
        $otherAddress = Address::factory()->forUser($other)->forCountry($this->uae)->create();
        $order = persistOrder($other, $otherAddress);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 404 ERR_NOT_FOUND when the order id does not exist', function (): void {
        ['token' => $token] = makeListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders/9999999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 for a non-numeric id (route does not match)', function (): void {
        ['token' => $token] = makeListCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/orders/not-a-number');

        $response->assertStatus(404);
    });

    it('returns 401 without a bearer token', function (): void {
        $user = User::factory()->customer()->create();
        $addr = Address::factory()->forUser($user)->forCountry($this->uae)->create();
        $order = persistOrder($user, $addr);

        $response = $this->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns Arabic product_name snapshot when Accept-Language: ar', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeListCustomer();
        $order = persistOrder($user, $address);
        OrderItem::factory()->forOrder($order)->withUnitPrice(500.00, 1)->state([
            'product_name_snapshot' => ['en' => 'Water Filter PW50', 'ar' => 'فلتر مياه PW50'],
        ])->create();

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'Accept-Language' => 'ar',
        ])->getJson("/api/v1/orders/{$order->id}");

        $response->assertOk();
        expect($response->json('data.items.0.product_name'))->toBe('فلتر مياه PW50');
    });
});
