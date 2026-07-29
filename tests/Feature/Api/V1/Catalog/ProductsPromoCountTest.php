<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/products/{id} — promotions_count (Phase 7 retroactive wire)
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/promotions.md § 7. Phase 6 shipped
| `promotions_count = 0` as a literal placeholder; Phase 7 upgrades it via
| `PromotionEngine::countActiveForProduct()`. The count covers all three
| `applies_to` scopes (all / products / categories) and only currently-
| active rows.
*/

beforeEach(function (): void {
    $this->seed(CategoriesSeeder::class);
    $this->seed(ProductCatalogSeeder::class);
});

describe('promotions_count — scope=all', function (): void {
    it('includes an active applies_to=all promotion in the count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        Promotion::factory()->active()->appliesToAll()->create([
            'code' => 'SITEWIDE10',
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        expect($response->json('data.promotions_count'))->toBe(1);
    });

    it('counts multiple sitewide promotions cumulatively', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        Promotion::factory()->active()->appliesToAll()->create(['code' => 'A1']);
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'A2']);
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'A3']);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(3);
    });
});

describe('promotions_count — scope=products', function (): void {
    it('counts a product-scoped promotion when the product is in the pivot', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $promo = Promotion::factory()->active()->appliesToProducts()->create([
            'code' => 'PW50M-ONLY',
        ]);
        $promo->products()->attach($product->id);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(1);
    });

    it('does NOT count a product-scoped promotion when the product is NOT in the pivot', function (): void {
        $pw50m = Product::query()->where('code', 'PW50M')->firstOrFail();
        $pw50r = Product::query()->where('code', 'PW50R')->firstOrFail();

        $promo = Promotion::factory()->active()->appliesToProducts()->create([
            'code' => 'PW50R-ONLY',
        ]);
        $promo->products()->attach($pw50r->id);

        $response = $this->getJson("/api/v1/products/{$pw50m->id}");

        // PW50M is not in the pivot — count must be 0.
        expect($response->json('data.promotions_count'))->toBe(0);
    });
});

describe('promotions_count — scope=categories', function (): void {
    it('counts a category-scoped promotion when the product belongs to the category', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $promo = Promotion::factory()->active()->appliesToCategories()->create([
            'code' => 'CT-ONLY',
        ]);
        $promo->categories()->attach($product->category_id);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(1);
    });

    it('does NOT count a category-scoped promotion when the product is in a different category', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        $otherCategory = Category::query()->where('code', 'commercial')->firstOrFail();

        // Sanity — PW50M is NOT in the commercial category.
        expect($product->category_id)->not->toBe($otherCategory->id);

        $promo = Promotion::factory()->active()->appliesToCategories()->create([
            'code' => 'COMMERCIAL-ONLY',
        ]);
        $promo->categories()->attach($otherCategory->id);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });
});

describe('promotions_count — visibility filtering', function (): void {
    it('excludes inactive promotions from the count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        Promotion::factory()->appliesToAll()->inactive()->create(['code' => 'INACTIVE-X']);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });

    it('excludes expired promotions from the count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        Promotion::factory()->expired()->appliesToAll()->create(['code' => 'EXPIRED-X']);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });

    it('excludes upcoming (not-yet-started) promotions from the count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        Promotion::factory()->upcoming()->appliesToAll()->create(['code' => 'UPCOMING-X']);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });

    it('excludes soft-deleted promotions from the count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $promo = Promotion::factory()->active()->appliesToAll()->create(['code' => 'SOFT-DEL']);
        $promo->delete();

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(0);
    });

    it('mixes the three scopes correctly into a single de-duplicated count', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        // One sitewide.
        Promotion::factory()->active()->appliesToAll()->create(['code' => 'MIX-ALL']);
        // One product-scoped attached to PW50M.
        $byProduct = Promotion::factory()->active()->appliesToProducts()->create(['code' => 'MIX-PROD']);
        $byProduct->products()->attach($product->id);
        // One category-scoped attached to PW50M's category.
        $byCategory = Promotion::factory()->active()->appliesToCategories()->create(['code' => 'MIX-CAT']);
        $byCategory->categories()->attach($product->category_id);
        // One product-scoped attached to a different product → must NOT count.
        $other = Product::query()->where('code', 'PW50R')->firstOrFail();
        $unrelated = Promotion::factory()->active()->appliesToProducts()->create(['code' => 'MIX-UNRELATED']);
        $unrelated->products()->attach($other->id);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        expect($response->json('data.promotions_count'))->toBe(3);
    });
});
