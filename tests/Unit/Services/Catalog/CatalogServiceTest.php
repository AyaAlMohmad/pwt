<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\Catalog\CatalogService;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/*
|--------------------------------------------------------------------------
| CatalogService — Phase 6 read-only catalog queries.
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/catalog.md § 8.8.
| - listCategories(?string $search): Collection
| - findCategory(int $id): Category   — throws ModelNotFoundException
| - listProducts(array $filters): LengthAwarePaginator
| - findProduct(int $id): Product     — throws ModelNotFoundException
| - resolveCurrency(array $filters): string
*/

beforeEach(function (): void {
    $this->service = new CatalogService;
});

describe('CatalogService::listCategories', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
    });

    it('returns only active categories sorted by display_order', function (): void {
        Category::query()->where('code', 'commercial')->update(['is_active' => false]);

        $result = $this->service->listCategories();

        $codes = $result->pluck('code')->all();
        expect($codes)->not->toContain('commercial');
        // display_order ordering preserved.
        expect($codes)->toBe([
            'counter-top-filters',
            'under-sink-filters',
            'whole-house-filtration',
            'spare-parts',
        ]);
    });

    it('excludes soft-deleted categories', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        $cat->delete();

        $result = $this->service->listCategories();

        $codes = $result->pluck('code')->all();
        expect($codes)->not->toContain('spare-parts');
    });

    it('applies the localized search filter against the active locale', function (): void {
        $result = $this->service->listCategories('Under');

        expect($result)->toHaveCount(1);
        expect($result->first()->code)->toBe('under-sink-filters');
    });

    it('returns an empty collection when no categories match the search', function (): void {
        $result = $this->service->listCategories('does-not-exist-xyz');

        expect($result)->toBeEmpty();
    });

    it('treats an empty-string search as no filter', function (): void {
        $result = $this->service->listCategories('');

        expect($result)->toHaveCount(5);
    });

    it('treats a null search as no filter', function (): void {
        $result = $this->service->listCategories(null);

        expect($result)->toHaveCount(5);
    });

    it('returns no rows when the table is empty', function (): void {
        Category::query()->forceDelete();

        $result = $this->service->listCategories();

        expect($result)->toBeEmpty();
    });
});

describe('CatalogService::findCategory', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
    });

    it('returns the active category with products_count loaded', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();

        $found = $this->service->findCategory($cat->id);

        expect($found)->toBeInstanceOf(Category::class);
        expect($found->id)->toBe($cat->id);
        expect($found->products_count)->toBeInt()->toBeGreaterThan(0);
    });

    it('counts only active products toward products_count', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();

        // Deactivate every product in the category.
        Product::query()
            ->where('category_id', $cat->id)
            ->update(['is_active' => false]);

        $found = $this->service->findCategory($cat->id);

        expect($found->products_count)->toBe(0);
    });

    it('throws ModelNotFoundException for an inactive category', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        Category::query()->where('id', $cat->id)->update(['is_active' => false]);

        expect(fn () => $this->service->findCategory($cat->id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('throws ModelNotFoundException for an unknown id', function (): void {
        expect(fn () => $this->service->findCategory(99999))
            ->toThrow(ModelNotFoundException::class);
    });

    it('throws ModelNotFoundException for a soft-deleted category', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        $cat->delete();

        expect(fn () => $this->service->findCategory($cat->id))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('CatalogService::listProducts', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
    });

    it('returns a LengthAwarePaginator with the default page size of 15', function (): void {
        $result = $this->service->listProducts([]);

        expect($result)->toBeInstanceOf(LengthAwarePaginator::class);
        expect($result->perPage())->toBe(15);
        expect($result->total())->toBe(16);
    });

    it('clamps per_page to the max of 100', function (): void {
        $result = $this->service->listProducts(['per_page' => 9999]);

        // Form Request normally raises 422; the service still defensively
        // clamps in case it's called directly (unit tests, internal use).
        expect($result->perPage())->toBe(100);
    });

    it('clamps per_page to a minimum of 1 (defensive)', function (): void {
        $result = $this->service->listProducts(['per_page' => 0]);

        expect($result->perPage())->toBe(1);
    });

    it('eager-loads the category, images, and activePrices relations', function (): void {
        $result = $this->service->listProducts(['per_page' => 1]);

        $product = $result->first();
        expect($product->relationLoaded('category'))->toBeTrue();
        expect($product->relationLoaded('images'))->toBeTrue();
        expect($product->relationLoaded('activePrices'))->toBeTrue();
    });

    it('filters by category_id via the inCategory scope', function (): void {
        $commercial = Category::query()->where('code', 'commercial')->firstOrFail();

        $result = $this->service->listProducts(['category_id' => $commercial->id, 'per_page' => 100]);

        foreach ($result->items() as $product) {
            expect($product->category_id)->toBe($commercial->id);
        }
    });

    it('filters by featured=true via the featured scope', function (): void {
        $result = $this->service->listProducts(['featured' => true, 'per_page' => 100]);

        $codes = collect($result->items())->pluck('code')->all();
        expect($codes)->toEqualCanonicalizing(['2iM-B', 'PW50M', 'PW50R']);
    });

    it('hides inactive products via the visibleToCustomers scope', function (): void {
        Product::query()->where('code', 'PW50M')->update(['is_active' => false]);

        $result = $this->service->listProducts(['per_page' => 100]);

        $codes = collect($result->items())->pluck('code')->all();
        expect($codes)->not->toContain('PW50M');
    });

    it('applies the localized search across name + descriptions', function (): void {
        $result = $this->service->listProducts(['search' => 'PW50', 'per_page' => 100]);

        $codes = collect($result->items())->pluck('code')->all();
        expect($codes)->toContain('PW50M');
        expect($codes)->toContain('PW50R');
    });

    it('applies min_price against the resolved currency', function (): void {
        $result = $this->service->listProducts([
            'min_price' => 99999,
            'per_page' => 100,
        ]);

        expect($result->total())->toBe(0);
    });

    it('applies term filter and excludes products with no price for that term', function (): void {
        $result = $this->service->listProducts(['term' => 'rent', 'per_page' => 100]);

        // Quote-only products (no prices) are excluded.
        $codes = collect($result->items())->pluck('code')->all();
        expect($codes)->not->toContain('BWT');
        expect($codes)->not->toContain('BV-M');
        expect($codes)->not->toContain('BV-R');
    });

    it('sorts by created_at descending by default', function (): void {
        $result = $this->service->listProducts(['per_page' => 100]);

        $created = collect($result->items())->pluck('created_at')->all();
        $sorted = $created;
        usort($sorted, fn ($a, $b) => $b <=> $a);
        expect($created)->toBe($sorted);
    });

    it('sorts by name ASC when sort=name and order=asc', function (): void {
        $result = $this->service->listProducts([
            'sort' => 'name',
            'order' => 'asc',
            'per_page' => 100,
        ]);

        // Pull the localized English name from the model and assert sort.
        $names = collect($result->items())
            ->map(fn (Product $p) => $p->getTranslation('name', 'en'))
            ->all();

        $sorted = $names;
        sort($sorted);
        expect($names)->toBe($sorted);
    });

    it('sorts by name DESC when sort=name and order=desc', function (): void {
        $result = $this->service->listProducts([
            'sort' => 'name',
            'order' => 'desc',
            'per_page' => 100,
        ]);

        $names = collect($result->items())
            ->map(fn (Product $p) => $p->getTranslation('name', 'en'))
            ->all();

        $sorted = $names;
        rsort($sorted);
        expect($names)->toBe($sorted);
    });

    it('appends the filters onto the paginator links', function (): void {
        $result = $this->service->listProducts(['per_page' => 5, 'search' => 'PW']);

        $url = $result->url(1);
        expect($url)->toContain('search=PW');
        expect($url)->toContain('per_page=5');
    });
});

describe('CatalogService::findProduct', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
    });

    it('returns the active product with relations eager-loaded', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();

        $found = $this->service->findProduct($product->id);

        expect($found)->toBeInstanceOf(Product::class);
        expect($found->id)->toBe($product->id);
        expect($found->relationLoaded('category'))->toBeTrue();
        expect($found->relationLoaded('images'))->toBeTrue();
        expect($found->relationLoaded('specs'))->toBeTrue();
        expect($found->relationLoaded('activePrices'))->toBeTrue();
    });

    it('throws ModelNotFoundException for an inactive product', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        Product::query()->where('id', $product->id)->update(['is_active' => false]);

        expect(fn () => $this->service->findProduct($product->id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('throws ModelNotFoundException for an unknown id', function (): void {
        expect(fn () => $this->service->findProduct(99999))
            ->toThrow(ModelNotFoundException::class);
    });

    it('throws ModelNotFoundException for a soft-deleted product', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        $product->delete();

        expect(fn () => $this->service->findProduct($product->id))
            ->toThrow(ModelNotFoundException::class);
    });

    it('eager-loads only the active price rows', function (): void {
        $product = Product::query()->where('code', 'PW50M')->firstOrFail();
        // Add an inactive row — it must NOT be in `activePrices`.
        ProductPrice::factory()->forProduct($product)->inactive()->create([
            'term' => 'buy',
            'currency' => 'SAR',
            'amount' => '9999.99',
        ]);

        $found = $this->service->findProduct($product->id);

        $amounts = $found->activePrices->pluck('amount')->all();
        expect($amounts)->not->toContain('9999.99');
    });
});

describe('CatalogService::resolveCurrency', function (): void {
    it('defaults to AED when the filter is absent', function (): void {
        expect($this->service->resolveCurrency([]))->toBe('AED');
    });

    it('uppercases the supplied currency', function (): void {
        expect($this->service->resolveCurrency(['currency' => 'sar']))->toBe('SAR');
    });

    it('defaults to AED when the currency value is an empty string', function (): void {
        expect($this->service->resolveCurrency(['currency' => '']))->toBe('AED');
    });

    it('defaults to AED when the currency value is non-string', function (): void {
        expect($this->service->resolveCurrency(['currency' => 123]))->toBe('AED');
    });
});
