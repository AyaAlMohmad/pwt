<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * Seed 2 demo promo codes:
 *   - WELCOME10 — percent 10%, max AED 100, applies to all.
 *   - SUMMER50  — fixed AED 50, min order AED 500, applies to all.
 *
 * Env-gated to local/development/testing. Idempotent via
 * `updateOrCreate` keyed on `code`.
 */
final class PromotionsSeeder extends Seeder
{
    public function run(): void
    {
        $env = app()->environment();
        if (! in_array($env, ['local', 'development', 'testing'], true)) {
            $this->command?->warn(
                "[PromotionsSeeder] Skipped: refusing to seed demo promotions in '$env' environment.",
            );

            return;
        }

        foreach ($this->promotions() as $row) {
            Promotion::updateOrCreate(
                ['code' => $row['code']],
                $row,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function promotions(): array
    {
        return [
            [
                'code' => 'WELCOME10',
                'name' => [
                    'en' => 'Welcome 10% Off',
                    'ar' => 'خصم ترحيبي 10%',
                ],
                'description' => [
                    'en' => 'Get 10% off your first purchase, up to AED 100.',
                    'ar' => 'احصل على خصم 10% على أول عملية شراء، حتى 100 درهم.',
                ],
                'type' => DiscountType::Percent->value,
                'value' => 10.00,
                'max_discount_amount' => 100.00,
                'currency' => null,
                'min_order_amount' => null,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(90),
                'per_user_limit' => 1,
                'total_limit' => null,
                'applies_to' => PromoScope::All->value,
                'is_active' => true,
            ],
            [
                'code' => 'SUMMER50',
                'name' => [
                    'en' => 'Summer AED 50 Off',
                    'ar' => 'خصم 50 درهم في الصيف',
                ],
                'description' => [
                    'en' => 'AED 50 off any order of AED 500 or more.',
                    'ar' => 'خصم 50 درهم على أي طلب بقيمة 500 درهم أو أكثر.',
                ],
                'type' => DiscountType::Fixed->value,
                'value' => 50.00,
                'max_discount_amount' => null,
                'currency' => 'AED',
                'min_order_amount' => 500.00,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(60),
                'per_user_limit' => 1,
                'total_limit' => 1000,
                'applies_to' => PromoScope::All->value,
                'is_active' => true,
            ],
        ];
    }
}
