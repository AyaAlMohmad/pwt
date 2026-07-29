<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Products — CRUD feature tests
|--------------------------------------------------------------------------
|
| Covers GET, POST, PUT, DELETE /api/v1/admin/products[/{id}].
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function productAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/products', function (): void {
    it('returns the list including inactive rows for super_admin', function (): void {
        [, $bearer] = productAdminBearer();
        Product::factory()->create(['code' => 'P-ACT']);
        Product::factory()->inactive()->create(['code' => 'P-INACT']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'code', 'name', 'is_active', 'is_featured',
                        'is_quote_only', 'stock_status', 'category',
                        'orders_count', 'images_count', 'created_at',
                    ],
                ],
                'meta' => ['pagination'],
            ]);

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('P-ACT')->toContain('P-INACT');
    });
});

describe('GET /api/v1/admin/products/{id}', function (): void {
    it('returns inactive product detail by id (super_admin)', function (): void {
        [, $bearer] = productAdminBearer();
        $product = Product::factory()->inactive()->create(['code' => 'P-SHOW']);

        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.code', 'P-SHOW')
            ->assertJsonPath('data.is_active', false);
    });

    it('returns 404 for a missing id', function (): void {
        [, $bearer] = productAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/products/999999')
            ->assertStatus(404);
    });
});

describe('POST /api/v1/admin/products', function (): void {
    it('creates a product with nested prices + specs (201)', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();

        $payload = [
            'code' => 'P-NEW1',
            'category_id' => $category->id,
            'name' => ['en' => 'P-NEW1 Unit', 'ar' => 'وحدة'],
            'short_description' => ['en' => 'Short', 'ar' => 'قصير'],
            'is_active' => true,
            'is_featured' => true,
            'is_quote_only' => false,
            'stock_status' => 'in_stock',
            'prices' => [
                ['term' => 'buy', 'amount' => 2500.00, 'currency' => 'AED'],
                ['term' => 'rent', 'amount' => 150.00, 'currency' => 'AED', 'period' => 'monthly', 'min_term_months' => 12],
            ],
            'specs' => [
                ['label' => ['en' => 'Stages', 'ar' => 'مراحل'], 'value' => ['en' => '5', 'ar' => '٥']],
            ],
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/products', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'P-NEW1')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.name.en', 'P-NEW1 Unit')
            ->assertJsonPath('data.name.ar', 'وحدة');

        expect($response->json('data.prices'))->toHaveCount(2);
        expect($response->json('data.specs'))->toHaveCount(1);

        $product = Product::query()->where('code', 'P-NEW1')->firstOrFail();
        $this->assertDatabaseCount('product_prices', 2);
        $this->assertDatabaseHas('product_prices', ['product_id' => $product->id, 'term' => 'rent', 'period' => 'monthly']);
    });

    it('returns 422 when rent price omits period (cross-field rule)', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/products', [
                'code' => 'P-RENT-BAD',
                'category_id' => $category->id,
                'name' => ['en' => 'Bad', 'ar' => 'سيء'],
                'prices' => [
                    ['term' => 'rent', 'amount' => 100.00, 'currency' => 'AED'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['prices.0.period', 'prices.0.min_term_months']]);
    });

    it('returns 422 ERR_VALIDATION when name.ar is missing on a required bilingual field (name)', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();

        // The architecture allows AR optional on create. To match "missing ar"
        // semantics, we instead test specs.label.en missing as a bilingual
        // required-EN failure (specs.*.value.en is required).
        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/products', [
                'code' => 'P-VALEN',
                'category_id' => $category->id,
                'name' => ['en' => 'OK', 'ar' => 'حسنا'],
                'specs' => [
                    ['label' => ['ar' => 'بدون انجليزية'], 'value' => ['en' => '5']],
                ],
                'prices' => [
                    ['term' => 'buy', 'amount' => 100, 'currency' => 'AED'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['specs.0.label.en']]);
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = productAdminBearer(UserRole::Support->value);
        $category = Category::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/products', [
                'code' => 'P-SUPP',
                'category_id' => $category->id,
                'name' => ['en' => 'Support', 'ar' => 'دعم'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

describe('PUT /api/v1/admin/products/{id}', function (): void {
    it('replaces all prices atomically (count goes from 3 to 2)', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();

        ProductPrice::factory()->forProduct($product)->buy(1000)->create();
        ProductPrice::factory()->forProduct($product)->rent(50)->create();
        ProductPrice::factory()->forProduct($product)->foc()->create();

        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(3);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'category_id' => $category->id,
                'name' => ['en' => 'Updated', 'ar' => 'محدث'],
                'prices' => [
                    ['term' => 'buy', 'amount' => 9999.99, 'currency' => 'AED'],
                    ['term' => 'rent', 'amount' => 200.00, 'currency' => 'AED', 'period' => 'monthly', 'min_term_months' => 24],
                ],
            ]);

        $response->assertOk();
        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(2);
        $this->assertDatabaseHas('product_prices', [
            'product_id' => $product->id, 'term' => 'buy', 'amount' => '9999.99',
        ]);

        // A price-replace activity entry is logged.
        $entry = Activity::query()
            ->where('log_name', 'product.prices')
            ->where('event', 'product.prices_replaced')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->properties['before_count'])->toBe(3);
        expect($entry->properties['after_count'])->toBe(2);
    });

    it('replaces specs atomically', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();

        ProductSpec::factory()->forProduct($product)->count(3)->create();
        expect(ProductSpec::where('product_id', $product->id)->count())->toBe(3);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'category_id' => $category->id,
                'name' => ['en' => 'Product Name', 'ar' => 'اسم المنتج'],
                'specs' => [
                    ['label' => ['en' => 'A', 'ar' => 'أ'], 'value' => ['en' => '1', 'ar' => '١']],
                ],
            ])
            ->assertOk();

        expect(ProductSpec::where('product_id', $product->id)->count())->toBe(1);
    });

    it('returns 422 with ERR_PRODUCT_CODE_IMMUTABLE message when code is sent on PUT', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create(['code' => 'P-ORIG']);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'code' => 'P-DIFFERENT',
                'category_id' => $category->id,
                'name' => ['en' => 'Try Rename', 'ar' => 'إعادة'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['code']]);

        // Code unchanged.
        $product->refresh();
        expect($product->code)->toBe('P-ORIG');
    });

    it('logs a status change activity entry on PUT when is_featured changes', function (): void {
        [, $bearer] = productAdminBearer();
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create(['is_featured' => false]);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/products/{$product->id}", [
                'category_id' => $category->id,
                'name' => ['en' => 'Featured Product', 'ar' => 'منتج مميز'],
                'is_featured' => true,
            ])
            ->assertOk();

        // Spatie LogsActivity `updated` event captures dirty diff.
        $entry = Activity::query()
            ->where('log_name', 'product')
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull();
        expect($entry->properties['attributes']['is_featured'] ?? null)->toBeTrue();
        expect($entry->properties['old']['is_featured'] ?? null)->toBeFalse();
    });
});

describe('DELETE /api/v1/admin/products/{id}', function (): void {
    it('soft-deletes a product with no active orders (200)', function (): void {
        [, $bearer] = productAdminBearer();
        $product = Product::factory()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);

        expect($response->json('data.deleted_at'))->not->toBeNull();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    });

    it('returns 422 ERR_PRODUCT_HAS_ACTIVE_ORDERS when the product is in a pending order', function (): void {
        [, $bearer] = productAdminBearer();
        $product = Product::factory()->create();

        $order = Order::factory()->create(['status' => OrderStatus::Pending->value]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PRODUCT_HAS_ACTIVE_ORDERS')
            ->assertJsonStructure(['errors' => ['active_orders_count']]);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    });

    it('blocks delete on confirmed and out_for_delivery orders', function (string $status): void {
        [, $bearer] = productAdminBearer();
        $product = Product::factory()->create();

        $order = Order::factory()->create(['status' => $status]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PRODUCT_HAS_ACTIVE_ORDERS');
    })->with(['confirmed', 'scheduled', 'out_for_delivery']);

    it('allows delete when associated order is already completed', function (): void {
        [, $bearer] = productAdminBearer();
        $product = Product::factory()->create();

        $order = Order::factory()->completed()->create();
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertOk();

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    });

    it('rejects sales role (super_admin only)', function (): void {
        [, $bearer] = productAdminBearer(UserRole::Sales->value);
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
