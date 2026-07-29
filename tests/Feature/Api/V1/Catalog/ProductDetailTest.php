<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/products/{id}
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/catalog.md § 1, § 4.3. Public endpoint.
| Returns the full ProductDetailResource: id, code, name, description,
| benefits, images (primary first), specs (ordered), all active prices,
| category embed, is_quote_only, stock_status, is_featured,
| promotions_count placeholder, created_at.
*/

beforeEach(function (): void {
    $this->seed(CategoriesSeeder::class);
    $this->seed(ProductCatalogSeeder::class);
});

describe('GET /api/v1/products/{id} — happy paths', function (): void {
    it('returns 200 with the full ProductDetailResource shape', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'code', 'name', 'short_description',
                    'description', 'benefits',
                    'primary_image_url',
                    'images', 'specs', 'prices',
                    'category' => [
                        'id', 'code', 'name', 'description', 'image_url',
                        'products_count',
                        'is_active', 'display_order', 'created_at',
                    ],
                    'is_quote_only', 'stock_status', 'is_featured',
                    'promotions_count', 'created_at',
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data.id'))->toBe($product->id);
        expect($response->json('data.code'))->toBe('PW50M');
        expect($response->json('data.is_featured'))->toBeTrue();
        expect($response->json('data.is_quote_only'))->toBeFalse();
        expect($response->json('data.stock_status'))->toBe('in_stock');
    });

    it('hardcodes promotions_count to 0 in Phase 6 (Phase 7 placeholder)', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });

    it('orders images with the primary first, then by display_order', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        // Add 3 more images: one non-primary at order 0, two at higher
        // orders. The seeded primary lives at display_order 0 already.
        ProductImage::factory()->forProduct($product)->create([
            'url' => 'products/PW50M/side.webp',
            'is_primary' => false,
            'display_order' => 2,
        ]);
        ProductImage::factory()->forProduct($product)->primary()->create([
            'url' => 'products/PW50M/hero.webp',
            'display_order' => 1,
        ]);
        ProductImage::factory()->forProduct($product)->create([
            'url' => 'products/PW50M/back.webp',
            'is_primary' => false,
            'display_order' => 3,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $images = $response->json('data.images');
        // Two primary images both come first, then non-primary ordered
        // by display_order (the relation orders by is_primary DESC,
        // display_order ASC, id ASC).
        expect($images[0]['is_primary'])->toBeTrue();
        expect($images[1]['is_primary'])->toBeTrue();
        expect($images[2]['is_primary'])->toBeFalse();
        expect($images[3]['is_primary'])->toBeFalse();
    });

    it('exposes ProductImage fields { id, url, alt_text, is_primary, display_order }', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $first = $response->json('data.images.0');
        expect(array_keys($first))->toEqualCanonicalizing([
            'id', 'url', 'alt_text', 'is_primary', 'display_order',
        ]);
        // URL should be absolute (resolved via Storage::url).
        expect($first['url'])->toBeString()->toStartWith('http');
    });

    it('orders specs by display_order ascending', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $orders = collect($response->json('data.specs'))
            ->pluck('display_order')
            ->all();
        $sorted = $orders;
        sort($sorted);
        expect($orders)->toBe($sorted);
    });

    it('exposes ProductSpec fields { id, label, value, display_order }', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $first = $response->json('data.specs.0');
        expect(array_keys($first))->toEqualCanonicalizing([
            'id', 'label', 'value', 'display_order',
        ]);
    });

    it('returns all active price rows across terms', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        // Seeded prices are AED buy and AED rent — 2 active rows.

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $prices = $response->json('data.prices');
        expect($prices)->toHaveCount(2);

        $terms = collect($prices)->pluck('term')->all();
        expect($terms)->toEqualCanonicalizing(['buy', 'rent']);
    });

    it('excludes inactive price rows from the prices array', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        // Add an inactive price row — it must NOT appear in the response.
        ProductPrice::factory()->forProduct($product)->inactive()->create([
            'term' => 'buy',
            'currency' => 'SAR',
            'amount' => '9999.99',
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $prices = $response->json('data.prices');
        $amounts = collect($prices)->pluck('amount')->all();
        expect($amounts)->not->toContain('9999.99');
    });

    it('exposes ProductPrice fields { id, term, amount, currency, period, min_term_months }', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $first = $response->json('data.prices.0');
        expect(array_keys($first))->toEqualCanonicalizing([
            'id', 'term', 'amount', 'currency', 'period', 'min_term_months',
        ]);
        // Amount serialised as a string per the resource (decimal(12,2)).
        expect($first['amount'])->toBeString();
    });

    it('returns the embedded category with products_count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $category = $response->json('data.category');
        expect($category['code'])->toBe('counter-top-filters');
        expect($category['id'])->toBe($product->category_id);
        // Architecture § 4.3: the detail endpoint embeds the category
        // with `products_count` (count of ACTIVE products in that
        // category). CatalogService::findProduct() eager-loads with
        // `withCount(['products' => is_active = 1])` and the
        // CategoryResource emits the value via `whenCounted`.
        expect($category)->toHaveKey('products_count');

        $expected = Product::query()
            ->where('category_id', $product->category_id)
            ->where('is_active', true)
            ->count();
        expect($category['products_count'])->toBe($expected);
    });

    it('does not require authentication (public endpoint)', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
    });

    it('returns the localized message in English by default', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('message'))->toBe('Product retrieved');
    });

    it('does not expose internal fields (display_order, is_active, updated_at, deleted_at)', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $data = $response->json('data');
        expect($data)->not->toHaveKey('display_order')
            ->and($data)->not->toHaveKey('is_active')
            ->and($data)->not->toHaveKey('updated_at')
            ->and($data)->not->toHaveKey('deleted_at');
    });
});

/*
|--------------------------------------------------------------------------
| Not-found / inactive
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products/{id} — error paths', function (): void {
    it('returns 404 ERR_NOT_FOUND for an inactive product', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        Product::query()->where('id', $product->id)->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 ERR_NOT_FOUND for an unknown numeric id', function (): void {
        $response = $this->getJson('/api/v1/products/999999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 for a non-numeric id (route constraint)', function (): void {
        $response = $this->getJson('/api/v1/products/abc');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 for a soft-deleted product', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        $product->delete();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual responses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products/{id} — bilingual', function (): void {
    it('returns Arabic spec labels and values when Accept-Language: ar', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        // Reset specs to known Arabic translations so we can assert exactly.
        ProductSpec::query()->where('product_id', $product->id)->delete();
        ProductSpec::factory()->forProduct($product)->create([
            'label' => ['en' => 'Filtration stages', 'ar' => 'مراحل الترشيح'],
            'value' => ['en' => '5', 'ar' => '٥'],
            'display_order' => 0,
        ]);

        $response = $this->getJson(
            "/api/v1/products/{$product->id}",
            ['Accept-Language' => 'ar'],
        );

        $spec = $response->json('data.specs.0');
        expect($spec['label'])->toBe('مراحل الترشيح');
        expect($spec['value'])->toBe('٥');
    });

    it('returns the localized message in Arabic when Accept-Language: ar', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson(
            "/api/v1/products/{$product->id}",
            ['Accept-Language' => 'ar'],
        );

        expect($response->json('message'))->toBe('تم استرجاع المنتج');
    });

    it('returns Arabic translation for benefits when stored', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        Product::query()->where('id', $product->id)->update([
            'benefits' => json_encode([
                'en' => ['Removes chlorine', '2-year filter life'],
                'ar' => ['يزيل الكلور', 'عمر فلتر سنتين'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->getJson(
            "/api/v1/products/{$product->id}",
            ['Accept-Language' => 'ar'],
        );

        $benefits = $response->json('data.benefits');
        expect($benefits)->toBe(['يزيل الكلور', 'عمر فلتر سنتين']);
    });

    it('returns null for benefits when no translation exists', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        // Seeder leaves benefits = null.
        expect($response->json('data.benefits'))->toBeNull();
    });
});
