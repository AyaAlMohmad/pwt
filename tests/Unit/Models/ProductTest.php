<?php

declare(strict_types=1);

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use Database\Seeders\CategoriesSeeder;

/*
|--------------------------------------------------------------------------
| Product model — scopes, relations, and translatable fields.
|--------------------------------------------------------------------------
|
| Phase 6 contract: docs/architecture/catalog.md § 8.7. The model defines
| `visibleToCustomers`, `featured`, `inCategory`, `active` scopes and
| relations to Category, ProductImage, ProductSpec, ProductPrice plus the
| `activePrices` filtered relation.
*/

beforeEach(function (): void {
    $this->seed(CategoriesSeeder::class);
    $this->category = Category::query()->where('code', 'counter-top-filters')->firstOrFail();
});

describe('Product scopes', function (): void {
    it('scopeActive filters out inactive products', function (): void {
        Product::factory()->forCategory($this->category)->active()->create(['code' => 'ACT-1']);
        Product::factory()->forCategory($this->category)->inactive()->create(['code' => 'INACT-1']);

        $codes = Product::query()->active()->pluck('code')->all();
        expect($codes)->toContain('ACT-1');
        expect($codes)->not->toContain('INACT-1');
    });

    it('scopeVisibleToCustomers filters out inactive products', function (): void {
        Product::factory()->forCategory($this->category)->active()->create(['code' => 'VIS-1']);
        Product::factory()->forCategory($this->category)->inactive()->create(['code' => 'HID-1']);

        $codes = Product::query()->visibleToCustomers()->pluck('code')->all();
        expect($codes)->toContain('VIS-1');
        expect($codes)->not->toContain('HID-1');
    });

    it('scopeFeatured returns only is_featured=true products', function (): void {
        Product::factory()->forCategory($this->category)->featured()->create(['code' => 'FEAT-1']);
        Product::factory()->forCategory($this->category)->create(['code' => 'NORM-1']);

        $codes = Product::query()->featured()->pluck('code')->all();
        expect($codes)->toContain('FEAT-1');
        expect($codes)->not->toContain('NORM-1');
    });

    it('scopeInCategory restricts to a single category', function (): void {
        $other = Category::query()->where('code', 'commercial')->firstOrFail();

        Product::factory()->forCategory($this->category)->create(['code' => 'IN-CAT-1']);
        Product::factory()->forCategory($other)->create(['code' => 'IN-OTHER-1']);

        $codes = Product::query()
            ->inCategory($this->category->id)
            ->pluck('code')->all();
        expect($codes)->toContain('IN-CAT-1');
        expect($codes)->not->toContain('IN-OTHER-1');
    });

    it('SoftDeletes global scope hides trashed products from default queries', function (): void {
        $product = Product::factory()->forCategory($this->category)->create(['code' => 'TRASHED-1']);
        $product->delete();

        $codes = Product::query()->pluck('code')->all();
        expect($codes)->not->toContain('TRASHED-1');
    });
});

describe('Product relations', function (): void {
    it('belongs to a Category', function (): void {
        $product = Product::factory()->forCategory($this->category)->create();

        expect($product->category)->toBeInstanceOf(Category::class);
        expect($product->category->id)->toBe($this->category->id);
    });

    it('has many images ordered with primary first, then by display_order, then id', function (): void {
        $product = Product::factory()->forCategory($this->category)->create();
        ProductImage::factory()->forProduct($product)->create(['display_order' => 2, 'is_primary' => false]);
        ProductImage::factory()->forProduct($product)->create(['display_order' => 1, 'is_primary' => false]);
        ProductImage::factory()->forProduct($product)->primary()->create(['display_order' => 5]);

        $images = $product->images()->get();
        expect($images->first()->is_primary)->toBeTrue();
        // After the primary, the next two must be ordered by display_order asc.
        expect($images[1]->display_order)->toBe(1);
        expect($images[2]->display_order)->toBe(2);
    });

    it('has many specs ordered by display_order then id', function (): void {
        $product = Product::factory()->forCategory($this->category)->create();
        ProductSpec::factory()->forProduct($product)->create(['display_order' => 2]);
        ProductSpec::factory()->forProduct($product)->create(['display_order' => 1]);
        ProductSpec::factory()->forProduct($product)->create(['display_order' => 0]);

        $orders = $product->specs()->pluck('display_order')->all();
        expect($orders)->toBe([0, 1, 2]);
    });

    it('has many prices (active + inactive) on the prices() relation', function (): void {
        $product = Product::factory()->forCategory($this->category)->create();
        ProductPrice::factory()->forProduct($product)->buy(1000.00)->create();
        ProductPrice::factory()->forProduct($product)->buy(2000.00)->inactive()->create([
            'currency' => 'SAR',
        ]);

        expect($product->prices()->count())->toBe(2);
    });

    it('has many activePrices filtered to is_active=true', function (): void {
        $product = Product::factory()->forCategory($this->category)->create();
        ProductPrice::factory()->forProduct($product)->buy(1000.00)->create();
        ProductPrice::factory()->forProduct($product)->buy(2000.00)->inactive()->create([
            'currency' => 'SAR',
        ]);

        expect($product->activePrices()->count())->toBe(1);
    });
});

describe('Product translatable fields', function (): void {
    it('returns the localized name when calling getTranslation', function (): void {
        $product = Product::factory()->forCategory($this->category)->create([
            'name' => ['en' => 'English Name', 'ar' => 'الاسم العربي'],
        ]);

        expect($product->getTranslation('name', 'en'))->toBe('English Name');
        expect($product->getTranslation('name', 'ar'))->toBe('الاسم العربي');
    });

    it('falls back to the configured fallback locale when a translation is missing', function (): void {
        $product = Product::factory()->forCategory($this->category)->create([
            'name' => ['en' => 'Only English'],
        ]);

        // Default fallback locale is `en` (CLAUDE.md § 3).
        expect($product->getTranslation('name', 'ar'))->toBe('Only English');
    });

    it('exposes the translatable property allowlist', function (): void {
        $product = new Product;
        expect($product->translatable)->toBe([
            'name', 'short_description', 'description', 'benefits',
        ]);
    });
});

describe('Product casts', function (): void {
    it('casts is_active, is_featured, is_quote_only to booleans', function (): void {
        $product = Product::factory()->forCategory($this->category)->create([
            'is_active' => 1,
            'is_featured' => 0,
            'is_quote_only' => 1,
        ]);

        expect($product->is_active)->toBeTrue();
        expect($product->is_featured)->toBeFalse();
        expect($product->is_quote_only)->toBeTrue();
    });

    it('casts stock_status to the StockStatus enum', function (): void {
        $product = Product::factory()->forCategory($this->category)->outOfStock()->create();

        expect($product->stock_status)->toBeInstanceOf(StockStatus::class);
        expect($product->stock_status)->toBe(StockStatus::OutOfStock);
    });
});
