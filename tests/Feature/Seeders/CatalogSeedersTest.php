<?php

declare(strict_types=1);

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;

/*
|--------------------------------------------------------------------------
| CategoriesSeeder
|--------------------------------------------------------------------------
*/

describe('CategoriesSeeder', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
    });

    it('produces exactly 5 categories', function (): void {
        expect(Category::query()->count())->toBe(5);
    });

    it('seeds the expected category codes', function (): void {
        $codes = Category::query()->orderBy('display_order')->pluck('code')->all();

        expect($codes)->toBe([
            'counter-top-filters',
            'under-sink-filters',
            'whole-house-filtration',
            'commercial',
            'spare-parts',
        ]);
    });

    it('seeds bilingual (en + ar) names for every category', function (): void {
        foreach (Category::query()->get() as $category) {
            $translations = $category->getTranslations('name');

            expect($translations)->toHaveKey('en');
            expect($translations)->toHaveKey('ar');
            expect($translations['en'])->toBeString()->not->toBeEmpty();
            expect($translations['ar'])->toBeString()->not->toBeEmpty();
        }
    });

    it('marks every seeded category as active', function (): void {
        $inactive = Category::query()->where('is_active', false)->count();
        expect($inactive)->toBe(0);
    });

    it('assigns a stable display_order to each category', function (): void {
        $rows = Category::query()
            ->orderBy('display_order')
            ->pluck('display_order', 'code')
            ->all();

        expect($rows)->toMatchArray([
            'counter-top-filters' => 1,
            'under-sink-filters' => 2,
            'whole-house-filtration' => 3,
            'commercial' => 4,
            'spare-parts' => 5,
        ]);
    });

    it('is idempotent — re-running does not duplicate rows', function (): void {
        $countBefore = Category::query()->count();

        $this->seed(CategoriesSeeder::class);

        expect(Category::query()->count())->toBe($countBefore);
    });
});

/*
|--------------------------------------------------------------------------
| ProductCatalogSeeder
|--------------------------------------------------------------------------
*/

describe('ProductCatalogSeeder', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
    });

    it('produces exactly 16 products', function (): void {
        expect(Product::query()->count())->toBe(16);
    });

    it('seeds the 16 SKU codes listed in docs/architecture/catalog.md § 8.6', function (): void {
        $codes = Product::query()->pluck('code')->all();

        $expected = [
            '2iM-B', '2iR-B', '3i-MB', 'BV-M', 'BV-R', 'BWT',
            'GEN3i-M', 'PW50-CT', 'PW50M', 'PW50R', 'PW90',
            'PW90-CT', 'PW90-R-CT', 'PW90CT', 'PW90M-CT', 'UnderSink',
        ];

        expect($codes)->toEqualCanonicalizing($expected);
    });

    it('marks every seeded product as active and in_stock', function (): void {
        $rows = Product::query()->get();

        foreach ($rows as $row) {
            expect($row->is_active)->toBeTrue();
            expect($row->stock_status)->toBe(StockStatus::InStock);
        }
    });

    it('marks BWT, BV-M, BV-R as is_quote_only=true with zero price rows', function (): void {
        $quoteOnly = ['BWT', 'BV-M', 'BV-R'];

        foreach ($quoteOnly as $code) {
            $product = Product::query()->where('code', $code)->firstOrFail();
            expect($product->is_quote_only)->toBeTrue();
            expect($product->prices()->count())->toBe(0);
        }
    });

    it('does NOT mark non-quote-only products as is_quote_only', function (): void {
        $non = Product::query()
            ->whereNotIn('code', ['BWT', 'BV-M', 'BV-R'])
            ->get();

        foreach ($non as $product) {
            expect($product->is_quote_only)->toBeFalse();
        }
    });

    it('marks the first three SKUs as featured (2iM-B, PW50M, PW50R)', function (): void {
        $featured = Product::query()->where('is_featured', true)->pluck('code')->all();
        expect($featured)->toEqualCanonicalizing(['2iM-B', 'PW50M', 'PW50R']);
    });

    it('seeds at least one primary image per product', function (): void {
        foreach (Product::query()->get() as $product) {
            $primary = ProductImage::query()
                ->where('product_id', $product->id)
                ->where('is_primary', true)
                ->count();

            expect($primary)->toBe(1, "Product {$product->code} should have exactly 1 primary image");
        }
    });

    it('seeds 4 placeholder specs per product', function (): void {
        foreach (Product::query()->get() as $product) {
            $count = ProductSpec::query()->where('product_id', $product->id)->count();
            expect($count)->toBe(4, "Product {$product->code} should have 4 specs");
        }
    });

    it('seeds AED buy + AED rent price rows for non-quote-only products', function (): void {
        $non = Product::query()
            ->whereNotIn('code', ['BWT', 'BV-M', 'BV-R'])
            ->get();

        foreach ($non as $product) {
            $prices = ProductPrice::query()->where('product_id', $product->id)->get();
            expect($prices)->toHaveCount(2, "Product {$product->code} should have 2 price rows");

            $terms = $prices->pluck('term')->map(fn ($t) => $t->value)->all();
            expect($terms)->toEqualCanonicalizing(['buy', 'rent']);

            $currencies = $prices->pluck('currency')->all();
            foreach ($currencies as $currency) {
                expect($currency)->toBe('AED');
            }
        }
    });

    it('assigns each product to a category (no orphan products)', function (): void {
        $orphans = Product::query()->whereNull('category_id')->count();
        expect($orphans)->toBe(0);
    });

    it('maps BWT, BV-M, BV-R to the commercial category', function (): void {
        $commercial = Category::query()->where('code', 'commercial')->firstOrFail();

        foreach (['BWT', 'BV-M', 'BV-R'] as $code) {
            $product = Product::query()->where('code', $code)->firstOrFail();
            expect($product->category_id)->toBe($commercial->id);
        }
    });

    it('maps UnderSink to the under-sink-filters category', function (): void {
        $under = Category::query()->where('code', 'under-sink-filters')->firstOrFail();
        $product = Product::query()->where('code', 'UnderSink')->firstOrFail();
        expect($product->category_id)->toBe($under->id);
    });

    it('maps PW90 to the whole-house-filtration category', function (): void {
        $wholeHouse = Category::query()->where('code', 'whole-house-filtration')->firstOrFail();
        $product = Product::query()->where('code', 'PW90')->firstOrFail();
        expect($product->category_id)->toBe($wholeHouse->id);
    });

    it('is idempotent — re-running does not duplicate products, images, specs, or prices', function (): void {
        $productsBefore = Product::query()->count();
        $imagesBefore = ProductImage::query()->count();
        $specsBefore = ProductSpec::query()->count();
        $pricesBefore = ProductPrice::query()->count();

        $this->seed(ProductCatalogSeeder::class);

        expect(Product::query()->count())->toBe($productsBefore);
        expect(ProductImage::query()->count())->toBe($imagesBefore);
        expect(ProductSpec::query()->count())->toBe($specsBefore);
        expect(ProductPrice::query()->count())->toBe($pricesBefore);
    });
});
