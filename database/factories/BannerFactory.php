<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Banner>
 */
final class BannerFactory extends Factory
{
    protected $model = Banner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'banner-'.Str::lower(Str::random(8)).'-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => $code,
            'placement' => BannerPlacement::Hero->value,
            'title' => [
                'en' => 'Banner '.$code,
                'ar' => 'بانر '.$code,
            ],
            'subtitle' => null,
            'image_url' => 'banners/'.$code.'/hero.webp',
            'image_url_ar' => null,
            'cta_label' => null,
            'cta_url' => null,
            'starts_at' => null,
            'ends_at' => null,
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => true]);
    }

    public function inactive(): self
    {
        return $this->state(fn (array $attrs): array => ['is_active' => false]);
    }

    public function placement(BannerPlacement $placement): self
    {
        return $this->state(fn (array $attrs): array => ['placement' => $placement->value]);
    }

    public function hero(): self
    {
        return $this->placement(BannerPlacement::Hero);
    }

    public function promo(): self
    {
        return $this->placement(BannerPlacement::Promo);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attrs): array => [
            'starts_at' => now()->subDays(30),
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

    public function withWindow(): self
    {
        return $this->state(fn (array $attrs): array => [
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    public function withArabicImage(): self
    {
        return $this->state(fn (array $attrs): array => [
            'image_url_ar' => 'banners/ar/'.fake()->uuid().'.webp',
        ]);
    }

    public function softDeleted(): self
    {
        return $this->state(fn (array $attrs): array => [
            'deleted_at' => now()->subMinute(),
        ]);
    }
}
