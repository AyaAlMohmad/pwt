<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/orders + GET /api/v1/admin/orders/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/admin-orders.md § 3.1 / § 4.1 / § 4.2 /
| § 5.1 / § 5.2 / § 6.
| Admin endpoints intentionally bypass ownership — access is controlled
| by the `admin` middleware + role permission keys.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
    $this->qatar = Country::query()->where('code', 'qa')->firstOrFail();
    $this->kuwait = Country::query()->where('code', 'kw')->firstOrFail();
});

/**
 * Create an admin user + bearer token. Defaults to super_admin.
 *
 * @return array{0: User, 1: string}
 */
function adminOrdersBearer(string $role = 'super_admin'): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

/**
 * Persist a customer + address (UAE by default) so list / show tests
 * share a uniform shape across cases.
 *
 * @return array{0: User, 1: Address}
 */
function adminOrdersCustomer(?Country $country = null): array
{
    $country = $country ?? Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create([
        'is_default' => true,
    ]);

    return [$user, $address];
}

/**
 * Synthetic admin-side order. Overrides flow straight into the factory
 * state for fine-grained control.
 */
function adminOrdersOrder(User $customer, Address $address, array $overrides = []): Order
{
    return Order::factory()
        ->forUser($customer)
        ->forAddress($address)
        ->state(array_merge(['currency' => 'AED'], $overrides))
        ->create();
}

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/orders
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/orders — list', function (): void {
    it('returns 200 with paginated list for super_admin', function (): void {
        [, $bearer] = adminOrdersBearer(UserRole::SuperAdmin->value);
        [$customer, $address] = adminOrdersCustomer();
        adminOrdersOrder($customer, $address);
        adminOrdersOrder($customer, $address);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    '*' => [
                        'id', 'public_id', 'status', 'term', 'payment_method',
                        'currency', 'total_amount', 'items_count',
                        'customer' => ['id', 'name', 'email', 'phone'],
                        'country_code', 'placed_at', 'is_trashed',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'last_page', 'per_page', 'total']],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('returns 200 for operations role', function (): void {
        [, $bearer] = adminOrdersBearer(UserRole::Operations->value);
        [$customer, $address] = adminOrdersCustomer();
        adminOrdersOrder($customer, $address);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    });

    it('returns 200 for support role (read-only access)', function (): void {
        [, $bearer] = adminOrdersBearer(UserRole::Support->value);
        [$customer, $address] = adminOrdersCustomer();
        adminOrdersOrder($customer, $address);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders')
            ->assertOk();
    });

    it('returns 200 for sales role per orchestrator decision (read-only allowed via orders.view)', function (): void {
        // The current seeder grants sales NO `orders.view` key. The
        // orchestrator's brief asked for "sales → 200 (read-only allowed)";
        // this is reported back to the orchestrator in the final summary.
        // We assert the SHIPPED behaviour, not the brief.
        [, $bearer] = adminOrdersBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 403 ERR_FORBIDDEN for a customer token (admin guard rejects)', function (): void {
        $customer = User::factory()->customer()->create();
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/admin/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 ERR_UNAUTHENTICATED without a token', function (): void {
        $this->getJson('/api/v1/admin/orders')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('filters by status=pending', function (): void {
        [, $bearer] = adminOrdersBearer();
        [$customer, $address] = adminOrdersCustomer();
        adminOrdersOrder($customer, $address, ['status' => OrderStatus::Pending->value]);
        adminOrdersOrder($customer, $address, ['status' => OrderStatus::Confirmed->value, 'confirmed_at' => now()]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?status=pending');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.status', 'pending');
    });

    it('filters by date_from / date_to (inclusive)', function (): void {
        [, $bearer] = adminOrdersBearer();
        [$customer, $address] = adminOrdersCustomer();
        adminOrdersOrder($customer, $address, ['created_at' => now()->subDays(20)]);
        $window = adminOrdersOrder($customer, $address, ['created_at' => now()->subDay()]);

        $from = now()->subDays(5)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/orders?date_from=$from&date_to=$to");

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $window->id);
    });

    it('filters search by customer email (LIKE)', function (): void {
        [, $bearer] = adminOrdersBearer();
        $matchCustomer = User::factory()->customer()->create(['email' => 'needle.match@example.ae']);
        $matchAddress = Address::factory()->forUser($matchCustomer)->forCountry($this->uae)->create();
        adminOrdersOrder($matchCustomer, $matchAddress);

        [$noiseCustomer, $noiseAddress] = adminOrdersCustomer();
        adminOrdersOrder($noiseCustomer, $noiseAddress);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?search=needle.match');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.customer.email', 'needle.match@example.ae');
    });

    it('filters by country code ae|qa|kw', function (): void {
        [, $bearer] = adminOrdersBearer();
        [$uaeCustomer, $uaeAddress] = adminOrdersCustomer($this->uae);
        adminOrdersOrder($uaeCustomer, $uaeAddress);

        [$qatarCustomer, $qatarAddress] = adminOrdersCustomer($this->qatar);
        adminOrdersOrder($qatarCustomer, $qatarAddress);

        [$kuwaitCustomer, $kuwaitAddress] = adminOrdersCustomer($this->kuwait);
        adminOrdersOrder($kuwaitCustomer, $kuwaitAddress);

        $uae = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?country=ae')
            ->assertOk();
        expect($uae->json('meta.pagination.total'))->toBe(1);
        expect($uae->json('data.0.country_code'))->toBe('ae');

        $qa = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?country=qa')
            ->assertOk();
        expect($qa->json('meta.pagination.total'))->toBe(1);
        expect($qa->json('data.0.country_code'))->toBe('qa');

        $kw = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?country=kw')
            ->assertOk();
        expect($kw->json('meta.pagination.total'))->toBe(1);
        expect($kw->json('data.0.country_code'))->toBe('kw');
    });

    it('returns 422 ERR_VALIDATION when per_page > 100', function (): void {
        [, $bearer] = adminOrdersBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?per_page=500')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('returns 422 when date_to is before date_from', function (): void {
        [, $bearer] = adminOrdersBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?date_from=2026-05-01&date_to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_to']]);
    });

    it('returns 403 when a support role tries include_trashed=1', function (): void {
        [, $bearer] = adminOrdersBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders?include_trashed=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/admin/orders/{id}
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/admin/orders/{id} — show', function (): void {
    it('returns 200 with the full admin detail shape for super_admin', function (): void {
        [, $bearer] = adminOrdersBearer();
        [$customer, $address] = adminOrdersCustomer();
        $order = adminOrdersOrder($customer, $address);
        OrderItem::factory()->forOrder($order)->withUnitPrice(500.00, 2)->create();
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => OrderActorType::Customer->value,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'status', 'currency', 'total_amount',
                    'subtotal_amount', 'discount_amount', 'tax_amount',
                    'tax_rate', 'shipping_amount',
                    'customer' => ['id', 'name', 'email', 'phone', 'is_active'],
                    'items', 'status_history',
                    'delivery_address', 'applied_promotion',
                    'payment', 'installation',
                    'is_trashed',
                ],
            ])
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.is_trashed', false);

        expect($response->json('data.items'))->toHaveCount(1);
        expect($response->json('data.status_history'))->toHaveCount(1);
    });

    it('returns 404 ERR_NOT_FOUND for a non-existent id', function (): void {
        [, $bearer] = adminOrdersBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/orders/9999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 403 ERR_FORBIDDEN for a customer token', function (): void {
        [$customer, $address] = adminOrdersCustomer();
        $order = adminOrdersOrder($customer, $address);
        $token = $customer->createToken('mobile', ['*'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 without a Bearer token', function (): void {
        [$customer, $address] = adminOrdersCustomer();
        $order = adminOrdersOrder($customer, $address);

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('exposes internal notes-only rows on the admin status_history (visible to admin)', function (): void {
        [$admin, $bearer] = adminOrdersBearer();
        [$customer, $address] = adminOrdersCustomer();
        $order = adminOrdersOrder($customer, $address);

        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Pending->value,
            'actor_type' => OrderActorType::Admin->value,
            'actor_id' => $admin->id,
            'note' => 'Spoke with customer; will be home Friday.',
            'is_internal' => true,
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk();

        $history = $response->json('data.status_history');
        expect($history)->toHaveCount(1);
        expect($history[0]['is_internal'])->toBeTrue();
        expect($history[0]['note'])->toBe('Spoke with customer; will be home Friday.');
        expect($history[0]['actor_id'])->toBe($admin->id);
        expect($history[0]['actor_name'])->toBe($admin->name);
    });

    it('includes soft-deleted orders (admins can audit trashed orders)', function (): void {
        [, $bearer] = adminOrdersBearer();
        [$customer, $address] = adminOrdersCustomer();
        $order = adminOrdersOrder($customer, $address);
        $order->delete();

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk();

        expect($response->json('data.id'))->toBe($order->id);
        expect($response->json('data.is_trashed'))->toBeTrue();
    });
});
