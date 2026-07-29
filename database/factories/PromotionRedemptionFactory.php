<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionRedemption>
 */
final class PromotionRedemptionFactory extends Factory
{
    protected $model = PromotionRedemption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'user_id' => User::factory(),
            'order_id' => null,
            'amount_applied' => 10.00,
            'currency' => 'AED',
            'applied_at' => now(),
        ];
    }

    public function forPromotion(Promotion $promotion): self
    {
        return $this->state(fn (array $attrs): array => ['promotion_id' => $promotion->id]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attrs): array => ['user_id' => $user->id]);
    }

    public function withAmount(float $amount, string $currency = 'AED'): self
    {
        return $this->state(fn (array $attrs): array => [
            'amount_applied' => $amount,
            'currency' => $currency,
        ]);
    }
}
