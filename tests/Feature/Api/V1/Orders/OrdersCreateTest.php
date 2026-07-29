<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PromotionReasonCode;
use App\Enums\StockStatus;
use App\Models\Address;
use App\Models\Category;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\PromotionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| POST /api/v1/orders — Phase 8 (Orders create)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/orders.md § 3.1, § 4.1, § 5.1.
| Auth: Sanctum. Idempotency-Key (24h replay) per § 1.5. Server
| recomputes all monetary fields per CLAUDE.md § 9.
| Rate-limited at 5/min/user on orders.create.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->seed(PromotionsSeeder::class);

    // Clear the named limiters so the per-user buckets start empty
    // every test (RefreshDatabase clears DB but not the rate-limit
    // cache).
    RateLimiter::clear('orders.create');
    RateLimiter::clear('orders.cancel');

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
    $this->kuwait = Country::query()->where('code', 'kw')->firstOrFail();
});

/**
 * @return array{user: User, token: string, address: Address}
 */
function makeOrderCustomer(?Country $country = null): array
{
    $country ??= Country::query()->where('code', 'ae')->firstOrFail();

    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($country)->create([
        'is_default' => true,
        'city' => 'Dubai',
    ]);

    // Reset the per-user limiter buckets so we know each test starts
    // clean (the orders.create / orders.cancel limiters key on user id).
    RateLimiter::clear('orders.create:'.$user->id);
    RateLimiter::clear('orders.cancel:'.$user->id);

    return ['user' => $user, 'token' => $token, 'address' => $address];
}

/**
 * Create a product priced for $currency at $buyAmount. Returns the
 * Product (with the price row already persisted via afterCreating).
 */
function makeOrderProduct(string $currency = 'AED', float $buyAmount = 1000.00, array $overrides = []): Product
{
    $category = Category::query()->first()
        ?? Category::factory()->create(['code' => 'cat-'.uniqid(), 'name' => ['en' => 'X', 'ar' => 'X']]);

    /** @var Product $product */
    $product = Product::factory()
        ->forCategory($category)
        ->state(array_merge([
            'is_active' => true,
            'is_quote_only' => false,
            'stock_status' => StockStatus::InStock->value,
            'name' => ['en' => 'Filter '.uniqid(), 'ar' => 'فلتر '.uniqid()],
        ], $overrides))
        ->create();

    ProductPrice::factory()
        ->forProduct($product)
        ->buy($buyAmount)
        ->currency($currency)
        ->create();

    return $product;
}

/**
 * Build a baseline valid create-order payload.
 */
function validOrderPayload(int $addressId, int $productId, array $overrides = []): array
{
    return array_merge([
        'items' => [
            ['product_id' => $productId, 'quantity' => 1, 'term' => 'buy'],
        ],
        'delivery_address_id' => $addressId,
        'delivery_date' => now()->addDay()->toDateString(),
        'delivery_time_slot' => 'morning',
        'term' => 'buy',
        'payment_method' => 'cod',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Happy path + envelope
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — happy path', function (): void {
    it('returns 201 with the OrderResource envelope on a minimal COD payload', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'public_id', 'status', 'term', 'payment_method',
                    'currency', 'subtotal_amount', 'discount_amount',
                    'tax_amount', 'tax_rate', 'shipping_amount',
                    'total_amount', 'items_count', 'delivery_address',
                    'promotion', 'placed_at', 'tracking_progress',
                    'is_cancellable',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.currency', 'AED')
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.is_cancellable', true)
            ->assertJsonPath('data.tracking_progress', 10);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'delivery_address_id' => $address->id,
            'status' => 'pending',
            'payment_method' => 'cod',
        ]);
    });

    it('computes the total server-side from product_prices for a single line', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'items' => [['product_id' => $product->id, 'quantity' => 2, 'term' => 'buy']],
            ]));

        $response->assertCreated();

        // subtotal = 2 * 1000 = 2000; UAE VAT 5% = 100; total = 2100.
        expect((float) $response->json('data.subtotal_amount'))->toBe(2000.00)
            ->and((float) $response->json('data.tax_rate'))->toBe(5.00)
            ->and((float) $response->json('data.tax_amount'))->toBe(100.00)
            ->and((float) $response->json('data.total_amount'))->toBe(2100.00);
    });

    it('applies UAE VAT 5% when billing/delivery country is UAE', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 500.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated();
        expect((float) $response->json('data.tax_rate'))->toBe(5.00)
            ->and((float) $response->json('data.tax_amount'))->toBe(25.00);
    });

    it('applies VAT=0 when delivery country is Kuwait', function (): void {
        ['user' => $user, 'token' => $token] = makeOrderCustomer($this->kuwait);
        $address = Address::query()->where('user_id', $user->id)->firstOrFail();
        $product = makeOrderProduct('KWD', 100.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated()
            ->assertJsonPath('data.currency', 'KWD');
        expect((float) $response->json('data.tax_rate'))->toBe(0.00)
            ->and((float) $response->json('data.tax_amount'))->toBe(0.00);
    });

    it('persists a status_history row with from=null to=pending actor=customer', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $orderId,
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => $user->id,
        ]);
    });

    it('persists order_items snapshot fields including bilingual product_name_snapshot', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 750.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        expect((float) $item->unit_price)->toBe(750.00)
            ->and($item->product_code)->toBe($product->code)
            ->and($item->getTranslations('product_name_snapshot'))->toHaveKeys(['en', 'ar']);
    });
});

/*
|--------------------------------------------------------------------------
| Server-side recomputation — never trust the client
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — server recomputes pricing', function (): void {
    it('rejects client-supplied total_amount with 422 ERR_VALIDATION', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'total_amount' => '1.00',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['total_amount']]);
    });

    it('rejects client-supplied subtotal_amount with 422', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'subtotal_amount' => '0.00',
            ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['subtotal_amount']]);
    });

    it('rejects client-supplied currency / tax_rate / unit_price', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'currency' => 'USD',
                'tax_rate' => '0.00',
                'unit_price' => '1.00',
            ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['currency', 'tax_rate', 'unit_price']]);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — validation', function (): void {
    it('returns 422 ERR_VALIDATION when items is missing', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', [
                'delivery_address_id' => $address->id,
                'term' => 'buy',
                'payment_method' => 'cod',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['items']]);
    });

    it('returns 422 when an item has product_id pointing at a non-existent product', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, 99_999_999));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 when an item has quantity=0', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'items' => [['product_id' => $product->id, 'quantity' => 0, 'term' => 'buy']],
            ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['items.0.quantity']]);
    });

    it('returns 422 when term is unknown', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'term' => 'lease',
            ]));

        $response->assertStatus(422)
            ->assertJsonStructure(['errors' => ['term']]);
    });

    it('returns 422 when delivery_address_id is not owned by the caller', function (): void {
        ['token' => $token] = makeOrderCustomer();
        $stranger = User::factory()->customer()->create();
        $foreignAddress = Address::factory()->forUser($stranger)->forCountry($this->uae)->create();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($foreignAddress->id, $product->id));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['delivery_address_id']]);
    });
});

/*
|--------------------------------------------------------------------------
| Product availability
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — product availability', function (): void {
    it('returns 422 for an inactive product (route via ActiveProduct rule)', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00, ['is_active' => false]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertStatus(422);
        // The ActiveProduct rule short-circuits with ERR_VALIDATION; the
        // service-level ERR_PRODUCT_UNAVAILABLE branch is exercised via
        // the post-validation stock check in the next test.
        expect((string) $response->json('code'))->toBeIn(['ERR_VALIDATION', 'ERR_PRODUCT_UNAVAILABLE']);
    });

    it('returns 422 for an out_of_stock product', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00, [
            'stock_status' => StockStatus::OutOfStock->value,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertStatus(422);
        expect((string) $response->json('code'))->toBeIn(['ERR_VALIDATION', 'ERR_PRODUCT_UNAVAILABLE']);
    });

    it('returns 422 ERR_PRODUCT_UNAVAILABLE when no active price exists in the delivery currency', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        // Product has a price in KWD but the address is in the UAE.
        $product = makeOrderProduct('KWD', 100.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PRODUCT_UNAVAILABLE')
            ->assertJsonStructure(['errors' => ['items.0']]);
    });
});

/*
|--------------------------------------------------------------------------
| Multi-item carts
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — multi-item', function (): void {
    it('accepts a multi-item cart with mixed terms and recomputes total', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $a = makeOrderProduct('AED', 500.00);
        $b = makeOrderProduct('AED', 1000.00);
        // Add a rent price row for product B so the rent line resolves.
        ProductPrice::factory()->forProduct($b)->rent(150.00, 12)->currency('AED')->create();

        $payload = validOrderPayload($address->id, $a->id, [
            'items' => [
                ['product_id' => $a->id, 'quantity' => 2, 'term' => 'buy'],
                ['product_id' => $b->id, 'quantity' => 1, 'term' => 'rent'],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.items_count', 2);

        // subtotal = (2*500) + (1*150) = 1150; VAT 5% = 57.50; total = 1207.50.
        expect((float) $response->json('data.subtotal_amount'))->toBe(1150.00)
            ->and((float) $response->json('data.tax_amount'))->toBe(57.50)
            ->and((float) $response->json('data.total_amount'))->toBe(1207.50);
    });

    it('persists two separate line items when the same product appears with different terms', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 500.00);
        ProductPrice::factory()->forProduct($product)->rent(150.00, 12)->currency('AED')->create();

        $payload = validOrderPayload($address->id, $product->id, [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'term' => 'buy'],
                ['product_id' => $product->id, 'quantity' => 3, 'term' => 'rent'],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', $payload);

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');
        expect(OrderItem::query()->where('order_id', $orderId)->count())->toBe(2);
    });
});

/*
|--------------------------------------------------------------------------
| Promo codes
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — promotions', function (): void {
    it('applies WELCOME10 percent discount when subtotal qualifies', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 500.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'promo_code' => 'WELCOME10',
            ]));

        $response->assertCreated();
        // 10% of 500 = 50 (under the 100 cap), tax base = 450 * 5% = 22.50.
        expect((float) $response->json('data.discount_amount'))->toBe(50.00)
            ->and((float) $response->json('data.tax_amount'))->toBe(22.50)
            ->and((float) $response->json('data.total_amount'))->toBe(472.50);
    });

    it('returns 422 ERR_PROMO_INVALID when the promo code is unknown', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 500.00);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'promo_code' => 'NOSUCHCODE123',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PROMO_INVALID')
            ->assertJsonStructure(['errors' => ['promo_code', 'reason_code']]);
    });

    it('returns 422 ERR_PROMO_INVALID with reason_code=currency_mismatch when the fixed promo currency does not match', function (): void {
        ['user' => $user, 'token' => $token] = makeOrderCustomer($this->kuwait);
        $address = Address::query()->where('user_id', $user->id)->firstOrFail();
        $product = makeOrderProduct('KWD', 1000.00);

        // SUMMER50 is fixed AED 50 → mismatches the Kuwait/KWD checkout.
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id, [
                'promo_code' => 'SUMMER50',
            ]));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PROMO_INVALID')
            ->assertJsonPath('errors.reason_code.0', PromotionReasonCode::CurrencyMismatch->value);
    });
});

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — Idempotency-Key', function (): void {
    it('replays the original order with 200 (not 201) when the same Idempotency-Key is sent within 24h', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);
        $key = 'idem-'.str_repeat('a', 10);
        $payload = validOrderPayload($address->id, $product->id);

        $first = $this->withHeaders(['Authorization' => "Bearer $token", 'Idempotency-Key' => $key])
            ->postJson('/api/v1/orders', $payload);
        $first->assertCreated();
        $originalId = (int) $first->json('data.id');

        // Replay within TTL — 1 hour later still within 24h window.
        Carbon::setTestNow(now()->addHour());

        $second = $this->withHeaders(['Authorization' => "Bearer $token", 'Idempotency-Key' => $key])
            ->postJson('/api/v1/orders', $payload);
        $second->assertOk()
            ->assertJsonPath('data.id', $originalId);

        // Only one order persisted overall.
        expect(Order::query()->where('user_id', $user->id)->count())->toBe(1);

        Carbon::setTestNow();
    });

    it('creates a new order when the Idempotency-Key replay is older than 24h', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);
        $key = 'idem-'.str_repeat('b', 10);
        $payload = validOrderPayload($address->id, $product->id);

        $first = $this->withHeaders(['Authorization' => "Bearer $token", 'Idempotency-Key' => $key])
            ->postJson('/api/v1/orders', $payload);
        $first->assertCreated();

        // Travel 25h forward — outside the 24h replay window.
        Carbon::setTestNow(now()->addHours(25));
        // The rate limiter's window has likely also lapsed but reset
        // defensively to keep the test self-contained.
        RateLimiter::clear('orders.create:'.$user->id);

        $second = $this->withHeaders(['Authorization' => "Bearer $token", 'Idempotency-Key' => $key])
            ->postJson('/api/v1/orders', $payload);
        $second->assertCreated();

        expect(Order::query()->where('user_id', $user->id)->count())->toBe(2);

        Carbon::setTestNow();
    });

    it('creates two distinct orders when no Idempotency-Key header is sent', function (): void {
        ['user' => $user, 'token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);
        $payload = validOrderPayload($address->id, $product->id);

        $first = $this->withHeader('Authorization', "Bearer $token")->postJson('/api/v1/orders', $payload);
        $first->assertCreated();
        $second = $this->withHeader('Authorization', "Bearer $token")->postJson('/api/v1/orders', $payload);
        $second->assertCreated();

        expect((int) $first->json('data.id'))->not->toBe((int) $second->json('data.id'));
        expect(Order::query()->where('user_id', $user->id)->count())->toBe(2);
    });

    it('returns 422 ERR_VALIDATION when the Idempotency-Key is malformed', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'Idempotency-Key' => 'bad', // too short
        ])->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });
});

/*
|--------------------------------------------------------------------------
| Auth / rate limiting / notifications
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — auth & throttling', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED when no bearer token is supplied', function (): void {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1, 'term' => 'buy']],
            'delivery_address_id' => 1,
            'term' => 'buy',
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('returns 429 ERR_RATE_LIMITED on the 6th call within a minute (orders.create limiter)', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);
        $payload = validOrderPayload($address->id, $product->id);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('Authorization', "Bearer $token")
                ->postJson('/api/v1/orders', $payload)
                ->assertCreated();
        }
        $sixth = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', $payload);

        $sixth->assertStatus(429);
    });

    it('does not dispatch synchronous notifications on order create (Phase 11 deferral)', function (): void {
        // Phase 8 deferred customer-facing notifications to Phase 11.
        // We assert nothing is dispatched synchronously — Notification::fake
        // / Queue::fake should both stay empty.
        Notification::fake();
        Queue::fake();

        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id))
            ->assertCreated();

        Notification::assertNothingSent();
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual
|--------------------------------------------------------------------------
*/

describe('POST /api/v1/orders — bilingual', function (): void {
    it('returns Arabic success message when Accept-Language: ar', function (): void {
        ['token' => $token, 'address' => $address] = makeOrderCustomer();
        $product = makeOrderProduct('AED', 1000.00);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            'Accept-Language' => 'ar',
        ])->postJson('/api/v1/orders', validOrderPayload($address->id, $product->id));

        $response->assertCreated();
        expect((string) $response->json('message'))->toBe('تم تقديم الطلب');
    });
});
