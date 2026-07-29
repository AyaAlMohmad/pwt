<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
final class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'PROMO'.Str::upper(Str::random(6)).fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => $code,
            'name' => [
                'en' => 'Promotion '.$code,
                'ar' => 'عرض '.$code,
            ],
            'description' => null,
            'type' => DiscountType::Percent->value,
            'value' => 10.00,
            'max_discount_amount' => null,
            'currency' => null,
            'min_order_amount' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'per_user_limit' => 1,
            'total_limit' => null,
            'applies_to' => PromoScope::All->value,
            'is_active' => true,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attrs): array => [
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => false]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attrs): array => [
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function upcoming(): self
    {
        return $this->state(fn (array $attrs): array => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    public function percent(float $value = 10.00, ?float $maxDiscount = null): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => DiscountType::Percent->value,
            'value' => $value,
            'max_discount_amount' => $maxDiscount,
            'currency' => null,
        ]);
    }

    public function fixed(float $value = 50.00, string $currency = 'AED'): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => DiscountType::Fixed->value,
            'value' => $value,
            'max_discount_amount' => null,
            'currency' => $currency,
        ]);
    }

    public function appliesToAll(): self
    {
        return $this->state(fn (array $attrs): array => [
            'applies_to' => PromoScope::All->value,
        ]);
    }

    public function appliesToProducts(): self
    {
        return $this->state(fn (array $attrs): array => [
            'applies_to' => PromoScope::Products->value,
        ]);
    }

    public function appliesToCategories(): self
    {
        return $this->state(fn (array $attrs): array => [
            'applies_to' => PromoScope::Categories->value,
        ]);
    }

    public function withMinOrder(float $minOrder): self
    {
        return $this->state(fn (array $attrs): array => [
            'min_order_amount' => $minOrder,
        ]);
    }

    public function withTotalLimit(int $limit): self
    {
        return $this->state(fn (array $attrs): array => [
            'total_limit' => $limit,
        ]);
    }

    public function withPerUserLimit(?int $limit): self
    {
        return $this->state(fn (array $attrs): array => [
            'per_user_limit' => $limit,
        ]);
    }

    public function withCode(string $code): self
    {
        return $this->state(fn (array $attrs): array => ['code' => Str::upper($code)]);
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinute(),
        ]);
    }
}
