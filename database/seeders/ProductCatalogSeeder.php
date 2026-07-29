<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductTerm;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use Illuminate\Database\Seeder;

/**
 * Seed the 16 unit-type products read from `Database for devlpt.xlsx`.
 *
 * Per docs/architecture/catalog.md § 8.6 + § 9.1–9.4 + CLAUDE.md § 6.2,
 * each row in this seeder corresponds to a distinct "Unit Type" value
 * surfaced across the UAE / Qatar / Kuwait sheets:
 *
 *   2iM-B, 2iR-B, 3i-MB, BV-M, BV-R, BWT, GEN3i-M, PW50-CT, PW50M,
 *   PW50R, PW90, PW90-CT, PW90-R-CT, PW90CT, PW90M-CT, UnderSink
 *
 * Category mapping is heuristic (see {@see categoryFor()}) — admin can
 * reassign via Phase 16. Bilingual names use an English fallback for
 * the AR locale (per § 9.2 — flagged for client review).
 *
 * Idempotency: `Product::updateOrCreate` is keyed on `code`. Images,
 * specs, and prices are inserted only when missing so reseeding does
 * not duplicate them.
 */
final class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Map category code → id once. Falls back to "counter-top" if a
        // category is missing (shouldn't happen — CategoriesSeeder runs
        // first per DatabaseSeeder ordering).
        $categoryIds = $this->loadCategoryIds();
        $fallbackCategoryId = $categoryIds['counter-top-filters'] ?? throw new \RuntimeException(
            'CategoriesSeeder must run before ProductCatalogSeeder.'
        );

        foreach ($this->productSeeds() as $index => $seed) {
            /** @var array{code: string, is_quote_only: bool, is_featured: bool} $seed */
            $code = $seed['code'];
            $categoryCode = $this->categoryFor($code);
            $categoryId = $categoryIds[$categoryCode] ?? $fallbackCategoryId;

            $product = Product::updateOrCreate(
                ['code' => $code],
                [
                    'category_id' => $categoryId,
                    'name' => [
                        'en' => $code.' Filtration Unit',
                        // TODO(client): translate to Arabic — placeholder per docs/architecture/catalog.md § 9.2.
                        'ar' => $code.' Filtration Unit',
                    ],
                    'short_description' => [
                        'en' => 'PWT model '.$code.'.',
                        'ar' => 'PWT model '.$code.'.',
                    ],
                    'description' => null,
                    'benefits' => null,
                    'is_active' => true,
                    'is_featured' => $seed['is_featured'],
                    'is_quote_only' => $seed['is_quote_only'],
                    'stock_status' => StockStatus::InStock->value,
                    'display_order' => $index,
                ],
            );

            $this->seedImage($product);
            $this->seedSpecs($product);

            if (! $seed['is_quote_only']) {
                $this->seedPrices($product);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function loadCategoryIds(): array
    {
        return Category::query()
            ->pluck('id', 'code')
            ->all();
    }

    /**
     * Heuristic SKU → category mapping. The admin can reassign via
     * Phase 16; the heuristic only seeds an initial sensible state.
     */
    private function categoryFor(string $code): string
    {
        return match (true) {
            $code === 'BWT', $code === 'BV-M', $code === 'BV-R' => 'commercial',
            $code === 'UnderSink' => 'under-sink-filters',
            $code === 'PW90' => 'whole-house-filtration',
            default => 'counter-top-filters',
        };
    }

    /**
     * The 16 unit-type seeds with their is_featured / is_quote_only flags.
     *
     * - `is_featured = true` for the first 3 codes (homepage carousel).
     * - `is_quote_only = true` for commercial-grade units (BWT, BV-M, BV-R).
     *
     * @return list<array{code: string, is_featured: bool, is_quote_only: bool}>
     */
    private function productSeeds(): array
    {
        return [
            ['code' => '2iM-B', 'is_featured' => true, 'is_quote_only' => false],
            ['code' => 'PW50M', 'is_featured' => true, 'is_quote_only' => false],
            ['code' => 'PW50R', 'is_featured' => true, 'is_quote_only' => false],
            ['code' => 'PW90', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'PW90CT', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'BV-M', 'is_featured' => false, 'is_quote_only' => true],
            ['code' => 'PW50-CT', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'BWT', 'is_featured' => false, 'is_quote_only' => true],
            ['code' => 'PW90-CT', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'PW90M-CT', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'GEN3i-M', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => '3i-MB', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'UnderSink', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => '2iR-B', 'is_featured' => false, 'is_quote_only' => false],
            ['code' => 'BV-R', 'is_featured' => false, 'is_quote_only' => true],
            ['code' => 'PW90-R-CT', 'is_featured' => false, 'is_quote_only' => false],
        ];
    }

    /**
     * Seed exactly one primary image per product. The URL is a stable
     * placeholder path; admin uploads the real media via Phase 16.
     */
    private function seedImage(Product $product): void
    {
        if ($product->images()->exists()) {
            return;
        }

        ProductImage::create([
            'product_id' => $product->id,
            'url' => 'products/'.$product->code.'/placeholder.webp',
            'alt_text' => [
                'en' => $product->code.' product image',
                'ar' => $product->code.' product image',
            ],
            'is_primary' => true,
            'display_order' => 0,
        ]);
    }

    /**
     * Seed 4 placeholder specs (Dimensions, Filtration stages, Flow rate,
     * Warranty). Admin overrides via Phase 16.
     */
    private function seedSpecs(Product $product): void
    {
        if ($product->specs()->exists()) {
            return;
        }

        $specs = [
            ['label' => ['en' => 'Dimensions', 'ar' => 'الأبعاد'], 'value' => ['en' => 'TBA', 'ar' => 'TBA']],
            ['label' => ['en' => 'Filtration stages', 'ar' => 'مراحل الترشيح'], 'value' => ['en' => 'TBA', 'ar' => 'TBA']],
            ['label' => ['en' => 'Flow rate', 'ar' => 'معدل التدفق'], 'value' => ['en' => 'TBA', 'ar' => 'TBA']],
            ['label' => ['en' => 'Warranty', 'ar' => 'الضمان'], 'value' => ['en' => '12 months', 'ar' => '12 months']],
        ];

        foreach ($specs as $index => $row) {
            ProductSpec::create([
                'product_id' => $product->id,
                'label' => $row['label'],
                'value' => $row['value'],
                'display_order' => $index,
            ]);
        }
    }

    /**
     * Seed an AED Buy row and an AED Rent/monthly row for non-quote
     * products. Phase 16 admin adds per-country currency rows.
     */
    private function seedPrices(Product $product): void
    {
        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'term' => ProductTerm::Buy->value,
                'currency' => 'AED',
            ],
            [
                'amount' => '2500.00',
                'period' => null,
                'min_term_months' => null,
                'is_active' => true,
            ],
        );

        ProductPrice::firstOrCreate(
            [
                'product_id' => $product->id,
                'term' => ProductTerm::Rent->value,
                'currency' => 'AED',
            ],
            [
                'amount' => '150.00',
                'period' => 'monthly',
                'min_term_months' => 12,
                'is_active' => true,
            ],
        );
    }
}
