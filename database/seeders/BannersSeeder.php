<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * Seed 3 demo banners (1 per placement: hero, promo, sidebar). Env-gated
 * to local/development/testing per docs/architecture/promotions.md
 * § 8.10. Idempotent via `updateOrCreate` keyed on `code`.
 */
final class BannersSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[BannersSeeder] Skipped: refusing to seed demo banners in '$env' environment.",
            );

            return;
        }

        foreach ($this->banners() as $row) {
            Banner::updateOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function banners(): array
    {
        return [
            [
                'code' => 'home-hero-2026',
                'placement' => BannerPlacement::Hero->value,
                'title' => [
                    'en' => 'Cool, clean water for every home',
                    'ar' => 'مياه نقية وباردة لكل منزل',
                ],
                'subtitle' => [
                    'en' => 'Save 15% on rentals through August',
                    'ar' => 'وفّر 15% على الإيجارات حتى أغسطس',
                ],
                'image_url' => 'banners/home-hero-2026/hero.webp',
                'image_url_ar' => null,
                'cta_label' => [
                    'en' => 'Browse rentals',
                    'ar' => 'تصفح الإيجارات',
                ],
                'cta_url' => '/api/v1/products?term=rent',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(90),
                'display_order' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'home-promo-summer-2026',
                'placement' => BannerPlacement::Promo->value,
                'title' => [
                    'en' => 'Summer Sale — code SUMMER50',
                    'ar' => 'تخفيضات الصيف — استخدم رمز SUMMER50',
                ],
                'subtitle' => null,
                'image_url' => 'banners/home-promo-summer-2026/promo.webp',
                'image_url_ar' => null,
                'cta_label' => [
                    'en' => 'Shop now',
                    'ar' => 'تسوق الآن',
                ],
                'cta_url' => '/api/v1/products',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(60),
                'display_order' => 0,
                'is_active' => true,
            ],
            [
                'code' => 'home-sidebar-quick-quote',
                'placement' => BannerPlacement::Sidebar->value,
                'title' => [
                    'en' => 'Need help choosing?',
                    'ar' => 'تحتاج مساعدة في الاختيار؟',
                ],
                'subtitle' => [
                    'en' => 'Request a free quote',
                    'ar' => 'اطلب عرض سعر مجاني',
                ],
                'image_url' => 'banners/home-sidebar-quick-quote/sidebar.webp',
                'image_url_ar' => null,
                'cta_label' => [
                    'en' => 'Get a quote',
                    'ar' => 'احصل على عرض سعر',
                ],
                'cta_url' => '/api/v1/contact',
                'starts_at' => null,
                'ends_at' => null,
                'display_order' => 0,
                'is_active' => true,
            ],
        ];
    }
}
