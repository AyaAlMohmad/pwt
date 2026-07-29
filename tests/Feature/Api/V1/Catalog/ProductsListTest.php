<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/products
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/catalog.md § 1, § 3.3, § 4.2. The endpoint is
| public, paginated, and filterable. The customer never supplies
| `status=` — visibility is enforced server-side via the
| `visibleToCustomers` scope.
|
| All tests seed both `CategoriesSeeder` and `ProductCatalogSeeder` so the
| 5 base categories + 16 SKUs are present.
*/

beforeEach(function (): void {
    $this->seed(CategoriesSeeder::class);
    $this->seed(ProductCatalogSeeder::class);
});

describe('GET /api/v1/products — happy paths', function (): void {
    it('returns 15 products on the default page (per_page=15) and paginates to last_page=2', function (): void {
        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'code', 'name', 'short_description',
                        'primary_image_url', 'starting_price',
                        'category', 'is_quote_only', 'stock_status',
                        'is_featured', 'created_at',
                    ],
                ],
                'meta' => ['pagination' => [
                    'current_page', 'per_page', 'total', 'last_page', 'from', 'to',
                ]],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(15);
        expect($response->json('meta.pagination.total'))->toBe(16);
        expect($response->json('meta.pagination.per_page'))->toBe(15);
        expect($response->json('meta.pagination.last_page'))->toBe(2);
    });

    it('honours per_page=5 and returns the correct pagination meta', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=5');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(5);
        expect($response->json('meta.pagination'))->toMatchArray([
            'current_page' => 1,
            'per_page' => 5,
            'total' => 16,
            'last_page' => 4,
            'from' => 1,
            'to' => 5,
        ]);
    });

    it('returns the message in the standard success envelope', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=3');

        expect($response->json('message'))->toBe('Products retrieved');
    });

    it('exposes only the allowlisted fields on each product (no description, no display_order)', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=1');

        $row = $response->json('data.0');
        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'code', 'name', 'short_description', 'primary_image_url',
            'starting_price', 'category', 'is_quote_only', 'stock_status',
            'is_featured', 'created_at',
        ]);
        expect($row)->not->toHaveKey('description')
            ->and($row)->not->toHaveKey('benefits')
            ->and($row)->not->toHaveKey('is_active')
            ->and($row)->not->toHaveKey('updated_at')
            ->and($row)->not->toHaveKey('deleted_at')
            ->and($row)->not->toHaveKey('display_order');
    });

    it('does not require authentication (public endpoint)', function (): void {
        $response = $this->getJson('/api/v1/products');

        $response->assertOk();
    });
});

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products — filters', function (): void {
    it('filters by category_id', function (): void {
        $commercial = Category::query()->where('code', 'commercial')->firstOrFail();

        $response = $this->getJson("/api/v1/products?category_id={$commercial->id}&per_page=100");

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        // BWT, BV-M, BV-R are the only 3 seeded commercial SKUs.
        expect($codes)->toEqualCanonicalizing(['BWT', 'BV-M', 'BV-R']);
    });

    it('matches PW50, PW50M, PW50R, PW50-CT when ?search=PW50', function (): void {
        $response = $this->getJson('/api/v1/products?search=PW50&per_page=100');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toContain('PW50M')
            ->and($codes)->toContain('PW50R')
            ->and($codes)->toContain('PW50-CT');
        // The PW90 family should NOT match the PW50 search.
        expect($codes)->not->toContain('PW90M-CT');
    });

    it('returns the buy-row starting_price when ?term=buy', function (): void {
        $response = $this->getJson('/api/v1/products?term=buy&per_page=100');

        $response->assertOk();
        $terms = collect($response->json('data'))
            ->pluck('starting_price.term')
            ->all();
        // Every returned starting_price.term must be "buy".
        foreach ($terms as $term) {
            expect($term)->toBe('buy');
        }
    });

    it('returns rent-only products and a rent starting_price when ?term=rent', function (): void {
        $response = $this->getJson('/api/v1/products?term=rent&per_page=100');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        // Quote-only products (BWT, BV-M, BV-R) have NO active prices so
        // they're filtered out of a term-scoped query — even though the
        // rest of the catalog is unfiltered they cannot match `term=rent`.
        expect($codes)->not->toContain('BWT');
        expect($codes)->not->toContain('BV-M');
        expect($codes)->not->toContain('BV-R');

        // The 13 non-quote-only seeded products should be present.
        expect($response->json('meta.pagination.total'))->toBe(13);
        foreach ($response->json('data') as $row) {
            expect($row['starting_price']['term'])->toBe('rent');
        }
    });

    it('includes products with at least one price >= min_price', function (): void {
        // All seeded buy prices are AED 2500. min_price=2000 includes them.
        $response = $this->getJson('/api/v1/products?min_price=2000&per_page=100');

        $response->assertOk();
        // 13 non-quote-only products have an AED 2500 buy row.
        expect($response->json('meta.pagination.total'))->toBe(13);
    });

    it('returns an empty list when min_price exceeds every price row', function (): void {
        $response = $this->getJson('/api/v1/products?min_price=99999&per_page=100');

        $response->assertOk();
        expect($response->json('meta.pagination.total'))->toBe(0);
        expect($response->json('data'))->toBe([]);
    });

    it('accepts ?max_price alone without min_price (no false cross-field failure)', function (): void {
        // Regression: previously the `gte:min_price` rule on max_price
        // triggered a 422 when min_price was absent. Per § 3.3 the
        // cross-field check applies ONLY when both bounds are sent.
        $response = $this->getJson('/api/v1/products?max_price=400&per_page=100');

        $response->assertOk()
            ->assertJsonPath('success', true);
        expect($response->json('errors'))->toBeNull();
        // Seeded SKUs all have buy=2500 and rent=150 → max_price=400 lets
        // the rent rows match. 13 non-quote-only products have such rows.
        expect($response->json('meta.pagination.total'))->toBe(13);
    });

    it('returns empty when ?currency=SAR is requested and no SAR price rows exist', function (): void {
        // No seeded SAR rows. min_price=1 forces the price-join filter to
        // exclude all products. (Without min_price, the seed catalog stays
        // in the list — the starting_price just becomes null. See § 1.5.)
        $response = $this->getJson('/api/v1/products?currency=SAR&min_price=1&per_page=100');

        $response->assertOk();
        expect($response->json('meta.pagination.total'))->toBe(0);
    });

    it('returns the featured products when ?featured=1 is set', function (): void {
        $response = $this->getJson('/api/v1/products?featured=1&per_page=100');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        // The seeder marks the first 3 codes as featured.
        expect($codes)->toEqualCanonicalizing(['2iM-B', 'PW50M', 'PW50R']);

        foreach ($response->json('data') as $row) {
            expect($row['is_featured'])->toBeTrue();
        }
    });

    it('accepts ?featured=true (string) and filters to featured products', function (): void {
        // Real-world clients (e.g. fetch wrappers, URL builders) commonly
        // emit `?featured=true` as a string. The Form Request coerces
        // these in prepareForValidation() so the `boolean` rule does not
        // reject them with a 422.
        $response = $this->getJson('/api/v1/products?featured=true&per_page=100');

        $response->assertOk()
            ->assertJsonPath('success', true);
        expect($response->json('errors'))->toBeNull();

        $codes = collect($response->json('data'))->pluck('code')->all();
        // Seeder marks 2iM-B, PW50M, PW50R as featured.
        expect($codes)->toEqualCanonicalizing(['2iM-B', 'PW50M', 'PW50R']);

        foreach ($response->json('data') as $row) {
            expect($row['is_featured'])->toBeTrue();
        }
    });

    it('surfaces a factory-created featured product alongside the seeded ones', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
        Product::factory()
            ->featured()
            ->forCategory($cat)
            ->withImages(1)
            ->withPrices()
            ->create(['code' => 'FACTORY-FEAT-1']);

        $response = $this->getJson('/api/v1/products?featured=1&per_page=100');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toContain('FACTORY-FEAT-1');
    });

    it('hides inactive products from the customer list', function (): void {
        // Toggle PW50M to inactive — it must disappear from the list.
        Product::query()->where('code', 'PW50M')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/products?per_page=100');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('PW50M');
        expect($response->json('meta.pagination.total'))->toBe(15);
    });

    it('hides soft-deleted products from the customer list', function (): void {
        $product = Product::query()->where('code', 'PW50R')->firstOrFail();
        $product->delete();

        $response = $this->getJson('/api/v1/products?per_page=100');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('PW50R');
        expect($response->json('meta.pagination.total'))->toBe(15);
    });

    it('surfaces the stock_status enum value (case: out_of_stock)', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
        Product::factory()
            ->outOfStock()
            ->forCategory($cat)
            ->withImages(1)
            ->withPrices()
            ->create(['code' => 'OUT-OF-STOCK-1']);

        $response = $this->getJson('/api/v1/products?per_page=100');

        $row = collect($response->json('data'))->firstWhere('code', 'OUT-OF-STOCK-1');
        expect($row['stock_status'])->toBe('out_of_stock');
    });

    it('returns is_quote_only=true AND starting_price=null for quote-only products', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=100');

        $bwt = collect($response->json('data'))->firstWhere('code', 'BWT');
        expect($bwt)->not->toBeNull();
        expect($bwt['is_quote_only'])->toBeTrue();
        expect($bwt['starting_price'])->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products — sorting', function (): void {
    it('sorts by price ascending when sort=price and order=asc (across priced products)', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();

        // 3 fresh products with distinct BUY prices. We then narrow the
        // query to term=buy so the seeded rent rows (AED 150) don't
        // pollute the resolved starting_price MIN.
        $cheap = Product::factory()->forCategory($cat)->withImages(1)
            ->create(['code' => 'CHEAP-100']);
        ProductPrice::factory()->forProduct($cheap)->buy(100.00)->create();

        $mid = Product::factory()->forCategory($cat)->withImages(1)
            ->create(['code' => 'MID-200']);
        ProductPrice::factory()->forProduct($mid)->buy(200.00)->create();

        $expensive = Product::factory()->forCategory($cat)->withImages(1)
            ->create(['code' => 'EXPENSIVE-300']);
        ProductPrice::factory()->forProduct($expensive)->buy(300.00)->create();

        // term=buy + max_price=400 → only our 3 new SKUs (every seeded
        // SKU has a buy row at 2500 which max_price filters out).
        $response = $this->getJson('/api/v1/products?sort=price&order=asc&term=buy&min_price=0&max_price=400&per_page=10');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toBe(['CHEAP-100', 'MID-200', 'EXPENSIVE-300']);
    });

    it('sorts by name descending when sort=name&order=desc', function (): void {
        $response = $this->getJson('/api/v1/products?sort=name&order=desc&per_page=5');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        // Sorted descending, so the array should be non-increasing.
        $sorted = $names;
        rsort($sorted);
        expect($names)->toBe($sorted);
    });

    it('sorts by name ascending by default when sort=name and order omitted', function (): void {
        $response = $this->getJson('/api/v1/products?sort=name&per_page=5');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $sorted = $names;
        sort($sorted);
        expect($names)->toBe($sorted);
    });
});

/*
|--------------------------------------------------------------------------
| Validation failures
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products — validation', function (): void {
    it('returns 422 ERR_VALIDATION when per_page exceeds 100', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=200');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_VALIDATION');

        expect($response->json('errors'))->toHaveKey('per_page');
    });

    it('returns 422 ERR_VALIDATION when sort is not in the whitelist', function (): void {
        $response = $this->getJson('/api/v1/products?sort=hacker');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.sort.0', 'One or more filter values are invalid.');
    });

    it('returns 422 ERR_VALIDATION when order is not asc/desc', function (): void {
        $response = $this->getJson('/api/v1/products?order=desct');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.order.0', 'One or more filter values are invalid.');
    });

    it('returns 422 ERR_VALIDATION for an unknown currency code', function (): void {
        $response = $this->getJson('/api/v1/products?currency=USD');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.currency.0', 'One or more filter values are invalid.');
    });

    it('returns 422 ERR_VALIDATION for an unknown term', function (): void {
        $response = $this->getJson('/api/v1/products?term=lease');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.term.0', 'One or more filter values are invalid.');
    });

    it('returns 422 when max_price < min_price', function (): void {
        $response = $this->getJson('/api/v1/products?min_price=500&max_price=100');

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.max_price.0', 'One or more filter values are invalid.');
    });

    it('returns 422 when category_id refers to an inactive category', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        Category::query()->where('id', $cat->id)->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/products?category_id={$cat->id}");

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.category_id.0', 'One or more filter values are invalid.');
    });

    it('returns 422 when category_id does not exist', function (): void {
        $response = $this->getJson('/api/v1/products?category_id=99999');

        $response->assertStatus(422)
            ->assertJsonPath('errors.category_id.0', 'One or more filter values are invalid.');
    });

    it('returns 422 when per_page=0', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=0');

        $response->assertStatus(422)
            ->assertJsonPath('errors.per_page.0', 'The per page field must be at least 1.');
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual responses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/products — bilingual', function (): void {
    it('returns English content by default', function (): void {
        // Seed an Arabic translation so the EN path is asserted positively.
        Product::query()
            ->where('code', 'PW50M')
            ->update([
                'name' => json_encode(['en' => 'PW50M Counter-top Filter', 'ar' => 'فلتر PW50M'], JSON_UNESCAPED_UNICODE),
            ]);

        $response = $this->getJson('/api/v1/products?search=PW50M&per_page=5');

        $row = collect($response->json('data'))->firstWhere('code', 'PW50M');
        expect($row['name'])->toBe('PW50M Counter-top Filter');
    });

    it('returns Arabic content when Accept-Language: ar', function (): void {
        Product::query()
            ->where('code', 'PW50M')
            ->update([
                'name' => json_encode(['en' => 'PW50M Counter-top Filter', 'ar' => 'فلتر PW50M'], JSON_UNESCAPED_UNICODE),
            ]);

        $response = $this->getJson(
            '/api/v1/products?search=%D9%81%D9%84%D8%AA%D8%B1&per_page=5',
            ['Accept-Language' => 'ar'],
        );

        $row = collect($response->json('data'))->firstWhere('code', 'PW50M');
        expect($row['name'])->toBe('فلتر PW50M');
    });

    it('returns the Arabic message when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=1', ['Accept-Language' => 'ar']);

        expect($response->json('message'))->toBe('تم استرجاع المنتجات');
    });

    it('returns the embedded category name in Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/products?per_page=5', ['Accept-Language' => 'ar']);

        $row = $response->json('data.0');
        // commercial = "تجاري", counter-top = "فلاتر سطح المطبخ", under-sink = "فلاتر تحت الحوض"
        expect($row['category']['name'])->toContain('ا'); // Arabic letter
        $arabicNames = ['تجاري', 'فلاتر سطح المطبخ', 'فلاتر تحت الحوض', 'فلترة المنزل بالكامل', 'قطع غيار'];
        expect($arabicNames)->toContain($row['category']['name']);
    });
});
