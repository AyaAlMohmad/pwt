<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Seed the 5 base catalog categories.
 *
 * Per docs/architecture/catalog.md § 9.1, the MVP ships with this fixed
 * set; admin (Phase 16) can rename, reorder, or add categories without
 * touching code. `firstOrCreate` is keyed on `code` so reseeding is
 * idempotent and any admin edits to the bilingual names / image_url
 * survive a re-run.
 *
 * Arabic names are best-effort placeholders — flagged for client review
 * at QA per § 9.2.
 */
final class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $row) {
            /** @var array{code: string, name: array{en: string, ar: string}, display_order: int} $row */
            Category::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => null,
                    'image_url' => null,
                    'display_order' => $row['display_order'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return list<array{
     *     code: string,
     *     name: array{en: string, ar: string},
     *     display_order: int
     * }>
     */
    private function categories(): array
    {
        return [
            [
                'code' => 'counter-top-filters',
                'name' => ['en' => 'Counter-top Filters', 'ar' => 'فلاتر سطح المطبخ'],
                'display_order' => 1,
            ],
            [
                'code' => 'under-sink-filters',
                'name' => ['en' => 'Under-sink Filters', 'ar' => 'فلاتر تحت الحوض'],
                'display_order' => 2,
            ],
            [
                'code' => 'whole-house-filtration',
                'name' => ['en' => 'Whole-house Filtration', 'ar' => 'فلترة المنزل بالكامل'],
                'display_order' => 3,
            ],
            [
                'code' => 'commercial',
                'name' => ['en' => 'Commercial', 'ar' => 'تجاري'],
                'display_order' => 4,
            ],
            [
                'code' => 'spare-parts',
                'name' => ['en' => 'Spare Parts', 'ar' => 'قطع غيار'],
                'display_order' => 5,
            ],
        ];
    }
}
