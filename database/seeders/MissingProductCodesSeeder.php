<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Seed / normalize the product codes that the Excel machine seeder
 * expects to be uppercase. Existing products with the same letters
 * (different case) are updated, missing products are created.
 */
final class MissingProductCodesSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            '2IM-B',
            '2IR-B',
            'BV-M',
            'PW90',
            'PW90-CT',
            'PW90M-CT',
            'PW50-CT',
            '3I-MB',
            'BWT',
            'UNDERSINK',
        ];

        $categoryId = $this->resolveCategoryId();

        foreach ($codes as $code) {
            // 1) Exact match, including trashed, to avoid unique-key
            //    collisions on soft-deleted rows.
            $product = Product::withTrashed()
                ->where('code', $code)
                ->first();

            if ($product !== null) {
                if ($product->trashed()) {
                    $product->restore();
                }
                $product->is_active = true;
                $product->save();
                continue;
            }

            // 2) Same letters in a different case (e.g. 2iM-B -> 2IM-B).
            $product = Product::query()
                ->whereRaw('UPPER(code) = ?', [$code])
                ->first();

            if ($product !== null) {
                $product->code = $code;
                $product->is_active = true;
                $product->save();
                continue;
            }

            // 3) Brand-new product.
            Product::create([
                'category_id' => $categoryId,
                'code' => $code,
                'name' => [
                    'en' => $code.' Filtration Unit',
                    'ar' => $code.' Filtration Unit',
                ],
                'short_description' => [
                    'en' => 'PWT model '.$code,
                    'ar' => 'PWT model '.$code,
                ],
                'description' => null,
                'benefits' => null,
                'is_active' => true,
                'is_featured' => false,
                'is_quote_only' => false,
                'stock_status' => StockStatus::InStock->value,
                'display_order' => 0,
            ]);
        }
    }

    private function resolveCategoryId(): int
    {
        $category = Category::query()
            ->where('code', 'counter-top-filters')
            ->first();

        if ($category !== null) {
            return $category->id;
        }

        $category = Category::query()->first();

        if ($category !== null) {
            return $category->id;
        }

        $category = Category::create([
            'code' => 'imported',
            'name' => [
                'en' => 'Imported',
                'ar' => 'Imported',
            ],
            'description' => null,
            'image_url' => null,
            'display_order' => 999,
            'is_active' => true,
        ]);

        return $category->id;
    }
}
