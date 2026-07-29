<?php

declare(strict_types=1);

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockStatus;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Exceptions\Orders\ProductUnavailableException;
use App\Exceptions\Orders\PromoInvalidException;
use App\Models\Address;
use App\Models\Category;
use App\Models\Country;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStatusTransitionService;
use App\Services\Promotions\PromotionEngine;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\PromotionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);
    $this->seed(PromotionsSeeder::class);

    $this->service = new OrderService(
        new PromotionEngine,
        new OrderStatusTransitionService,
    );

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
    $this->kuwait = Country::query()->where('code', 'kw')->firstOrFail();
});

function svcMakeProduct(string $currency = 'AED', float $buyAmount = 1000.00, array $overrides = []): Product
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
            'name' => ['en' => 'Test product '.uniqid(), 'ar' => 'منتج '.uniqid()],
        ], $overrides))
        ->create();

    ProductPrice::factory()->forProduct($product)->buy($buyAmount)->currency($currency)->create();

    return $product;
}

function svcMakeUserAndAddress(Country $country): array
{
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($country)->create([
        'is_default' => true,
    ]);

    return [$user, $address];
}

function svcCreatePayload(int $addressId, int $productId, array $overrides = []): array
{
    return array_merge([
        'items' => [['product_id' => $productId, 'quantity' => 1, 'term' => 'buy']],
        'delivery_address_id' => $addressId,
        'term' => 'buy',
        'payment_method' => 'cod',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| OrderService::create() — happy path + idempotency + tax
|--------------------------------------------------------------------------
*/

describe('OrderService::create()', function (): void {
    it('creates an order with replayed=false on first call', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 500.00);

        [$order, $replayed] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            null,
        );

        expect($replayed)->toBeFalse()
            ->and($order)->toBeInstanceOf(Order::class)
            ->and($order->status)->toBe(OrderStatus::Pending)
            ->and((float) $order->subtotal_amount)->toBe(500.00)
            ->and((float) $order->tax_amount)->toBe(25.00) // 5% of 500
            ->and((float) $order->total_amount)->toBe(525.00);
    });

    it('returns the existing order with replayed=true when the same Idempotency-Key is reused within TTL', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 1000.00);
        $key = 'unitidem-'.str_repeat('x', 10);

        [$first, $replayed1] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            $key,
        );
        [$second, $replayed2] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            $key,
        );

        expect($replayed1)->toBeFalse()
            ->and($replayed2)->toBeTrue()
            ->and($second->id)->toBe($first->id);
    });

    it('creates a new order when the Idempotency-Key replay is older than 24h', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 1000.00);
        $key = 'unitidem-'.str_repeat('y', 10);

        [$first] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            $key,
        );

        Carbon::setTestNow(now()->addHours(25));

        [$second, $replayed] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            $key,
        );

        expect($replayed)->toBeFalse()
            ->and($second->id)->not->toBe($first->id);

        Carbon::setTestNow();
    });

    it('throws ProductUnavailableException when there is no active price in the delivery currency', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        // Price exists only in KWD; the UAE delivery address forces AED.
        $product = svcMakeProduct('KWD', 100.00);

        $thrown = null;
        try {
            $this->service->create(
                $user,
                svcCreatePayload($address->id, $product->id),
                null,
            );
        } catch (ProductUnavailableException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->errorCode)->toBe('ERR_PRODUCT_UNAVAILABLE');
    });

    it('throws PromoInvalidException for an unknown promo code', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 1000.00);

        $thrown = null;
        try {
            $this->service->create(
                $user,
                svcCreatePayload($address->id, $product->id, ['promo_code' => 'NOSUCHTHING']),
                null,
            );
        } catch (PromoInvalidException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->errorCode)->toBe('ERR_PROMO_INVALID');
    });

    it('applies UAE VAT 5% on the (subtotal - discount) base', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 1000.00);

        [$order] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id, ['promo_code' => 'WELCOME10']),
            null,
        );

        // Subtotal 1000; 10% off = 100; tax base 900; VAT 5% = 45; total 945.
        expect((float) $order->discount_amount)->toBe(100.00)
            ->and((float) $order->tax_amount)->toBe(45.00)
            ->and((float) $order->total_amount)->toBe(945.00);
    });

    it('applies VAT=0 when the billing/delivery country is Kuwait', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->kuwait);
        $product = svcMakeProduct('KWD', 500.00);

        [$order] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            null,
        );

        expect((float) $order->tax_rate)->toBe(0.00)
            ->and((float) $order->tax_amount)->toBe(0.00)
            ->and($order->currency)->toBe('KWD');
    });

    it('writes the initial pending status_history row in the same transaction', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $product = svcMakeProduct('AED', 1000.00);

        [$order] = $this->service->create(
            $user,
            svcCreatePayload($address->id, $product->id),
            null,
        );

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => OrderActorType::Customer->value,
            'actor_id' => $user->id,
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| OrderService::cancel()
|--------------------------------------------------------------------------
*/

describe('OrderService::cancel()', function (): void {
    it('flips status to cancelled and writes a status_history row', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'pending',
            'currency' => 'AED',
        ])->create();

        $cancelled = $this->service->cancel($order, $user, 'Customer changed mind');

        expect($cancelled->status)->toBe(OrderStatus::Cancelled)
            ->and($cancelled->cancellation_reason)->toBe('Customer changed mind')
            ->and($cancelled->cancelled_by_user_id)->toBe($user->id);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'cancelled',
            'actor_type' => OrderActorType::Customer->value,
        ]);
    });

    it('throws InvalidStatusTransitionException when current status is out_for_delivery', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'out_for_delivery',
            'currency' => 'AED',
        ])->create();

        expect(fn () => $this->service->cancel($order, $user, 'Try too late'))
            ->toThrow(InvalidStatusTransitionException::class);
    });

    it('throws InvalidStatusTransitionException when the order is already cancelled', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'currency' => 'AED',
        ])->create();

        expect(fn () => $this->service->cancel($order, $user, 'Retry'))
            ->toThrow(InvalidStatusTransitionException::class);
    });
});

/*
|--------------------------------------------------------------------------
| OrderService::listForUser()
|--------------------------------------------------------------------------
*/

describe('OrderService::listForUser()', function (): void {
    it('filters by status', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'pending', 'currency' => 'AED',
        ])->create();
        Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'cancelled', 'cancelled_at' => now(), 'currency' => 'AED',
        ])->create();

        $paginator = $this->service->listForUser($user, ['status' => 'pending'], 1, 15);

        expect($paginator->total())->toBe(1);
        expect($paginator->items()[0]->status->value)->toBe('pending');
    });

    it('filters by date_from / date_to', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED', 'created_at' => now()->subDays(10),
        ])->create();
        Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED', 'created_at' => now()->subDay(),
        ])->create();

        $paginator = $this->service->listForUser($user, [
            'date_from' => now()->subDays(5)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ], 1, 15);

        expect($paginator->total())->toBe(1);
    });

    it('returns only the caller\'s orders', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $other = User::factory()->customer()->create();
        $otherAddress = Address::factory()->forUser($other)->forCountry($this->uae)->create();

        Order::factory()->forUser($user)->forAddress($address)->state(['currency' => 'AED'])->create();
        Order::factory()->forUser($other)->forAddress($otherAddress)->state(['currency' => 'AED'])->create();

        $paginator = $this->service->listForUser($user, [], 1, 15);
        expect($paginator->total())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| OrderService::tracking() + eagerLoad()
|--------------------------------------------------------------------------
*/

describe('OrderService::tracking()', function (): void {
    it('builds the tracking projection with progress and next_step', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'pending',
            'currency' => 'AED',
            'payment_method' => PaymentMethod::Cod->value,
        ])->create();
        OrderStatusHistory::factory()->forOrder($order)->create([
            'from_status' => null,
            'to_status' => 'pending',
            'actor_type' => 'customer',
        ]);

        $payload = $this->service->tracking($order);

        expect($payload['status'])->toBe('pending')
            ->and($payload['tracking_progress'])->toBe(10)
            ->and($payload['next_step']['status'])->toBe('confirmed')
            ->and($payload['timeline'])->toHaveCount(1);
    });

    it('returns next_step=null when the order is in a terminal status', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'completed',
            'completed_at' => now()->subDay(),
            'currency' => 'AED',
        ])->create();

        $payload = $this->service->tracking($order);

        expect($payload['status'])->toBe('completed')
            ->and($payload['tracking_progress'])->toBe(100)
            ->and($payload['next_step'])->toBeNull();
    });

    it('exposes the estimated_delivery_window when scheduled_for_at is set', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $when = now()->addDays(3)->setTime(10, 0);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'status' => 'scheduled',
            'scheduled_for_at' => $when,
            'currency' => 'AED',
        ])->create();

        $payload = $this->service->tracking($order);

        expect($payload['estimated_delivery_window'])->toBeArray()
            ->and($payload['estimated_delivery_window']['from'])->toBeString()
            ->and($payload['estimated_delivery_window']['to'])->toBeString();
    });
});

describe('OrderService::eagerLoad() & findForUser()', function (): void {
    it('eagerLoad() loads every relation needed by the customer resources', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $order = Order::factory()->forUser($user)->forAddress($address)->state([
            'currency' => 'AED',
        ])->create();

        $loaded = $this->service->eagerLoad($order);

        expect($loaded->relationLoaded('items'))->toBeTrue()
            ->and($loaded->relationLoaded('deliveryAddress'))->toBeTrue()
            ->and($loaded->relationLoaded('statusHistory'))->toBeTrue();
    });

    it('findForUser() aborts with 403 when the caller does not own the order', function (): void {
        [$user, $address] = svcMakeUserAndAddress($this->uae);
        $stranger = User::factory()->customer()->create();
        $order = Order::factory()->forUser($user)->forAddress($address)->state(['currency' => 'AED'])->create();

        expect(fn () => $this->service->findForUser($order, $stranger))
            ->toThrow(HttpException::class);
    });
});
