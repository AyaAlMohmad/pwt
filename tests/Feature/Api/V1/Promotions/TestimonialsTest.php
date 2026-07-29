<?php

declare(strict_types=1);

use App\Models\Testimonial;
use Database\Seeders\TestimonialsSeeder;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| GET /api/v1/testimonials
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/promotions.md § 1.1, § 4.4. Public,
| unpaginated, bounded. Visibility filtered server-side via
| `Testimonial::scopeActive()`. Sorted by display_order then id.
*/

beforeEach(function (): void {
    $this->seed(TestimonialsSeeder::class);
    RateLimiter::clear('public');
});

describe('GET /api/v1/testimonials — happy paths', function (): void {
    it('returns the 4 seeded testimonials with the standard envelope', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'name', 'role', 'company',
                        'avatar_url', 'rating', 'quote', 'display_order',
                    ],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(4);
    });

    it('does not include meta.pagination (bounded resource)', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        expect($response->json('meta'))->toBeNull();
    });

    it('does not require authentication (public endpoint)', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        $response->assertOk();
    });

    it('does not expose is_active or audit columns', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        $row = $response->json('data.0');
        expect(array_keys($row))->toEqualCanonicalizing([
            'id', 'name', 'role', 'company',
            'avatar_url', 'rating', 'quote', 'display_order',
        ]);
        expect($row)->not->toHaveKey('is_active')
            ->and($row)->not->toHaveKey('created_at')
            ->and($row)->not->toHaveKey('updated_at')
            ->and($row)->not->toHaveKey('deleted_at');
    });
});

/*
|--------------------------------------------------------------------------
| Visibility
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/testimonials — visibility', function (): void {
    it('hides inactive testimonials', function (): void {
        Testimonial::query()->where('name', 'Sara Al Mansouri')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/testimonials');

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->not->toContain('Sara Al Mansouri');
        expect($response->json('data'))->toHaveCount(3);
    });

    it('hides soft-deleted testimonials', function (): void {
        Testimonial::query()->where('name', 'Sara Al Mansouri')->firstOrFail()->delete();

        $response = $this->getJson('/api/v1/testimonials');

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->not->toContain('Sara Al Mansouri');
    });
});

/*
|--------------------------------------------------------------------------
| Sorting
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/testimonials — sorting', function (): void {
    it('sorts by display_order ascending', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        $orders = collect($response->json('data'))->pluck('display_order')->all();
        // Seeded display orders are 0, 1, 2, 3 — already ascending.
        expect($orders)->toBe([0, 1, 2, 3]);
        // Names should follow the seeded sequence too.
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toBe([
            'Sara Al Mansouri',
            'Mohammed Hassan',
            'Layla Al Bloushi',
            'Khaled Al Saud',
        ]);
    });

    it('places a newly-inserted testimonial with display_order=10 at the end', function (): void {
        Testimonial::factory()->create([
            'name' => 'Zara Final',
            'company' => null,
            'display_order' => 10,
            'is_active' => true,
            'quote' => ['en' => 'Late entry', 'ar' => 'دخول متأخر'],
        ]);

        $response = $this->getJson('/api/v1/testimonials');

        $last = collect($response->json('data'))->last();
        expect($last['name'])->toBe('Zara Final');
    });
});

/*
|--------------------------------------------------------------------------
| Bilingual responses
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/testimonials — bilingual', function (): void {
    it('returns English quotes by default', function (): void {
        $response = $this->getJson('/api/v1/testimonials');

        $sara = collect($response->json('data'))->firstWhere('name', 'Sara Al Mansouri');
        expect($sara['quote'])->toContain("PWT's filtration system completely changed our office");
    });

    it('returns Arabic quotes when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/testimonials', ['Accept-Language' => 'ar']);

        $sara = collect($response->json('data'))->firstWhere('name', 'Sara Al Mansouri');
        expect($sara['quote'])->toContain('لقد غيّر نظام التنقية من PWT');
    });

    it('returns the localized success message in Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/testimonials', ['Accept-Language' => 'ar']);

        expect($response->json('message'))->toBe('تم استرجاع آراء العملاء.');
    });

    it('returns name and company as plain (non-translated) strings even when locale=ar', function (): void {
        // Phase 7: only `quote` is translated. `name`, `role`, `company`
        // remain as stored.
        $response = $this->getJson('/api/v1/testimonials', ['Accept-Language' => 'ar']);

        $sara = collect($response->json('data'))->firstWhere('name', 'Sara Al Mansouri');
        expect($sara['name'])->toBe('Sara Al Mansouri');
        expect($sara['company'])->toBe('Acme Trading LLC');
        expect($sara['role'])->toBe('Office Manager');
    });
});
