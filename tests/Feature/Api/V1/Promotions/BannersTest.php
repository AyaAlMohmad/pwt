<?php

declare(strict_types=1);

use App\Enums\BannerPlacement;
use App\Models\Banner;
use Database\Seeders\BannersSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| GET /api/v1/banners
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/promotions.md § 1.1, § 1.4, § 4.1. Public,
| guest-readable, bounded (unpaginated). Visibility is enforced server-
| side via the `Banner::scopeActive()` (is_active=true AND inside the
| start/end window).
|
| Runs on the MySQL `pwt_testing` pipeline (Pest.php) — the resource
| reads translatable JSON via Spatie's HasTranslations and parallels the
| catalog module's read paths.
*/

beforeEach(function (): void {
    $this->seed(BannersSeeder::class);
    RateLimiter::clear('public');
});

describe('GET /api/v1/banners — happy paths', function (): void {
    it('returns 200 with the 3 seeded banners and the standard envelope', function (): void {
        $response = $this->getJson('/api/v1/banners');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'code', 'placement', 'title', 'subtitle',
                        'image_url', 'cta_label', 'cta_url',
                        'starts_at', 'ends_at',
                        'display_order', 'is_active',
                    ],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(3);
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toContain('home-hero-2026')
            ->and($codes)->toContain('home-promo-summer-2026')
            ->and($codes)->toContain('home-sidebar-quick-quote');
    });

    it('does not include a meta.pagination key (bounded resource)', function (): void {
        $response = $this->getJson('/api/v1/banners');

        $response->assertOk();
        expect($response->json('meta'))->toBeNull();
    });

    it('does not require authentication (public endpoint)', function (): void {
        $response = $this->getJson('/api/v1/banners');

        $response->assertOk();
    });

    it('does not expose internal columns (image_url_ar raw, created_at, deleted_at)', function (): void {
        $response = $this->getJson('/api/v1/banners');

        $row = $response->json('data.0');
        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'code', 'placement', 'title', 'subtitle',
            'image_url', 'cta_label', 'cta_url',
            'starts_at', 'ends_at',
            'display_order', 'is_active',
        ]);
        expect($row)->not->toHaveKey('image_url_ar')
            ->and($row)->not->toHaveKey('created_at')
            ->and($row)->not->toHaveKey('updated_at')
            ->and($row)->not->toHaveKey('deleted_at');
    });
});

/*
|--------------------------------------------------------------------------
| Visibility filtering
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/banners — visibility', function (): void {
    it('hides inactive banners from the customer list', function (): void {
        Banner::query()->where('code', 'home-hero-2026')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/banners');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('home-hero-2026');
        expect($response->json('data'))->toHaveCount(2);
    });

    it('hides banners whose starts_at is in the future', function (): void {
        Banner::factory()
            ->hero()
            ->upcoming()
            ->create(['code' => 'future-hero-banner']);

        $response = $this->getJson('/api/v1/banners');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('future-hero-banner');
        // The 3 seeded banners remain visible.
        expect($codes)->toContain('home-hero-2026');
    });

    it('hides banners whose ends_at is in the past', function (): void {
        Banner::factory()
            ->hero()
            ->expired()
            ->create(['code' => 'expired-hero-banner']);

        $response = $this->getJson('/api/v1/banners');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('expired-hero-banner');
    });

    it('reveals a scheduled banner once Carbon::setTestNow advances past its starts_at', function (): void {
        Banner::factory()->hero()->create([
            'code' => 'scheduled-banner',
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(40),
            'is_active' => true,
        ]);

        // Before the window opens.
        $codesBefore = collect($this->getJson('/api/v1/banners')->json('data'))->pluck('code')->all();
        expect($codesBefore)->not->toContain('scheduled-banner');

        // Advance time past starts_at — the banner becomes visible.
        Carbon::setTestNow(now()->addDays(10));

        $codesAfter = collect($this->getJson('/api/v1/banners')->json('data'))->pluck('code')->all();
        expect($codesAfter)->toContain('scheduled-banner');

        Carbon::setTestNow();
    });

    it('hides soft-deleted banners', function (): void {
        Banner::query()->where('code', 'home-hero-2026')->firstOrFail()->delete();

        $response = $this->getJson('/api/v1/banners');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('home-hero-2026');
        expect($response->json('data'))->toHaveCount(2);
    });

    it('shows banners with null starts_at / ends_at (no window bound = active immediately and indefinitely)', function (): void {
        // The seeded "home-sidebar-quick-quote" banner has null window — it must be visible.
        $response = $this->getJson('/api/v1/banners');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toContain('home-sidebar-quick-quote');
    });
});

/*
|--------------------------------------------------------------------------
| Placement filter
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/banners — placement filter', function (): void {
    it('returns only hero banners when ?placement=hero', function (): void {
        $response = $this->getJson('/api/v1/banners?placement=hero');

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            expect($row['placement'])->toBe(BannerPlacement::Hero->value);
        }
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.code'))->toBe('home-hero-2026');
    });

    it('returns only promo banners when ?placement=promo', function (): void {
        $response = $this->getJson('/api/v1/banners?placement=promo');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.code'))->toBe('home-promo-summer-2026');
        expect($response->json('data.0.placement'))->toBe(BannerPlacement::Promo->value);
    });

    it('accepts mixed-case placement values via prepareForValidation()', function (): void {
        $response = $this->getJson('/api/v1/banners?placement=HERO');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.placement'))->toBe('hero');
    });

    it('returns 422 ERR_VALIDATION when ?placement is not in the enum', function (): void {
        $response = $this->getJson('/api/v1/banners?placement=invalid');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_VALIDATION');

        expect($response->json('errors'))->toHaveKey('placement');
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual responses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/banners — bilingual', function (): void {
    it('returns English titles by default', function (): void {
        $response = $this->getJson('/api/v1/banners');

        $hero = collect($response->json('data'))->firstWhere('code', 'home-hero-2026');
        expect($hero['title'])->toBe('Cool, clean water for every home');
        expect($hero['subtitle'])->toBe('Save 15% on rentals through August');
        expect($hero['cta_label'])->toBe('Browse rentals');
    });

    it('returns Arabic titles when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/banners', ['Accept-Language' => 'ar']);

        $hero = collect($response->json('data'))->firstWhere('code', 'home-hero-2026');
        expect($hero['title'])->toBe('مياه نقية وباردة لكل منزل');
        expect($hero['subtitle'])->toBe('وفّر 15% على الإيجارات حتى أغسطس');
        expect($hero['cta_label'])->toBe('تصفح الإيجارات');
    });

    it('returns the localized success message in Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/banners', ['Accept-Language' => 'ar']);

        expect($response->json('message'))->toBe('تم استرجاع البانرات.');
    });

    it('returns image_url_ar when locale is ar and the banner has an Arabic image', function (): void {
        Banner::query()->where('code', 'home-hero-2026')->update([
            'image_url_ar' => 'banners/home-hero-2026/hero-ar.webp',
        ]);

        $en = $this->getJson('/api/v1/banners');
        $ar = $this->getJson('/api/v1/banners', ['Accept-Language' => 'ar']);

        $heroEn = collect($en->json('data'))->firstWhere('code', 'home-hero-2026');
        $heroAr = collect($ar->json('data'))->firstWhere('code', 'home-hero-2026');

        // EN locale → default image
        expect($heroEn['image_url'])->toContain('home-hero-2026/hero.webp');
        // AR locale → the dedicated Arabic image
        expect($heroAr['image_url'])->toContain('home-hero-2026/hero-ar.webp');
    });

    it('falls back to image_url for ar locale when image_url_ar is null', function (): void {
        // The seeded banners ship with image_url_ar = null.
        $response = $this->getJson('/api/v1/banners', ['Accept-Language' => 'ar']);

        $hero = collect($response->json('data'))->firstWhere('code', 'home-hero-2026');
        expect($hero['image_url'])->toContain('home-hero-2026/hero.webp');
    });
});
