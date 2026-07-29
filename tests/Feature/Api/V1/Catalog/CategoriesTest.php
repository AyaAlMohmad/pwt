<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\CategoriesSeeder;
use Database\Seeders\ProductCatalogSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/categories
|--------------------------------------------------------------------------
|
| Public, unpaginated list of active catalog categories. Contract from
| docs/architecture/catalog.md § 1 & § 4.1. The endpoint must:
|   - Return the standard success envelope.
|   - Return all active categories sorted by display_order then by the
|     localized name.
|   - Hide inactive categories from customers (§ 1.4).
|   - Respect Accept-Language for translatable fields.
|   - Require no authentication.
*/

describe('GET /api/v1/categories', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
    });

    it('returns 200 with the standard success envelope and the 5 seeded categories', function (): void {
        $response = $this->getJson('/api/v1/categories');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'description',
                        'image_url',
                        'is_active',
                        'display_order',
                        'created_at',
                    ],
                ],
            ]);

        expect($response->json())->toBeSuccessEnvelope();
        expect($response->json('data'))->toHaveCount(5);
    });

    it('does not include a pagination meta key on the unpaginated list', function (): void {
        $response = $this->getJson('/api/v1/categories');

        $response->assertOk();
        expect($response->json())->not->toHaveKey('meta');
    });

    it('sorts categories by display_order ascending', function (): void {
        $response = $this->getJson('/api/v1/categories');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toBe([
            'counter-top-filters',
            'under-sink-filters',
            'whole-house-filtration',
            'commercial',
            'spare-parts',
        ]);
    });

    it('filters the list by the optional ?search= query', function (): void {
        $response = $this->getJson('/api/v1/categories?search=Under');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->toBe(['under-sink-filters']);
    });

    it('returns an empty data array when search matches nothing', function (): void {
        $response = $this->getJson('/api/v1/categories?search=nonexistent-xyz');

        $response->assertOk();
        expect($response->json('data'))->toBe([]);
    });

    it('excludes inactive categories from the customer list', function (): void {
        Category::query()->where('code', 'commercial')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/categories');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('commercial');
        expect($response->json('data'))->toHaveCount(4);
    });

    it('excludes soft-deleted categories from the customer list', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        $cat->delete();

        $response = $this->getJson('/api/v1/categories');

        $codes = collect($response->json('data'))->pluck('code')->all();
        expect($codes)->not->toContain('spare-parts');
        expect($response->json('data'))->toHaveCount(4);
    });

    it('returns English category names by default', function (): void {
        $response = $this->getJson('/api/v1/categories');

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('Counter-top Filters');
        expect($names)->toContain('Commercial');
    });

    it('returns Arabic category names when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar']);

        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('فلاتر سطح المطبخ');
        expect($names)->toContain('تجاري');
    });

    it('returns the localized message for English by default', function (): void {
        $response = $this->getJson('/api/v1/categories');

        expect($response->json('message'))->toBe('Categories retrieved');
    });

    it('returns the localized message for Arabic when Accept-Language: ar', function (): void {
        $response = $this->getJson('/api/v1/categories', ['Accept-Language' => 'ar']);

        expect($response->json('message'))->toBe('تم استرجاع الفئات');
    });

    it('exposes only the allowlisted fields per category (no parent_id, updated_at, deleted_at)', function (): void {
        $response = $this->getJson('/api/v1/categories');

        $first = $response->json('data.0');
        expect(array_keys($first))->toEqualCanonicalizing([
            'id', 'code', 'name', 'description', 'image_url',
            'is_active', 'display_order', 'created_at',
        ]);
        expect($first)->not->toHaveKey('parent_id')
            ->and($first)->not->toHaveKey('updated_at')
            ->and($first)->not->toHaveKey('deleted_at')
            ->and($first)->not->toHaveKey('products_count');
    });

    it('does not require authentication (public endpoint)', function (): void {
        // No Authorization header — must succeed.
        $response = $this->getJson('/api/v1/categories');

        $response->assertOk();
    });

    it('rejects ?search= longer than 100 chars with 422 ERR_VALIDATION', function (): void {
        $response = $this->getJson('/api/v1/categories?search='.str_repeat('a', 101));

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonPath('errors.search.0', 'The search field must not be greater than 100 characters.');
    });
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/categories/{id}
|--------------------------------------------------------------------------
*/

describe('GET /api/v1/categories/{id}', function (): void {
    beforeEach(function (): void {
        $this->seed(CategoriesSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
    });

    it('returns 200 with the standard envelope and a products_count field', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();

        $response = $this->getJson("/api/v1/categories/{$cat->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id', 'code', 'name', 'description', 'image_url',
                    'products_count', 'is_active', 'display_order', 'created_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($cat->id);
        expect($response->json('data.code'))->toBe('counter-top-filters');
        expect($response->json('data.products_count'))->toBeInt()->toBeGreaterThan(0);
    });

    it('counts only active products toward products_count', function (): void {
        $cat = Category::query()->where('code', 'counter-top-filters')->firstOrFail();

        // Deactivate one product in the category.
        Product::query()
            ->where('category_id', $cat->id)
            ->where('is_active', true)
            ->limit(1)
            ->update(['is_active' => false]);

        $beforeCount = Product::query()
            ->where('category_id', $cat->id)
            ->where('is_active', true)
            ->count();

        $response = $this->getJson("/api/v1/categories/{$cat->id}");

        expect($response->json('data.products_count'))->toBe($beforeCount);
    });

    it('returns 404 ERR_NOT_FOUND for an inactive category', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        Category::query()->where('id', $cat->id)->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/categories/{$cat->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 ERR_NOT_FOUND for an unknown numeric id', function (): void {
        $response = $this->getJson('/api/v1/categories/999');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 for a non-numeric id (route constraint)', function (): void {
        $response = $this->getJson('/api/v1/categories/abc');

        // The whereNumber route constraint causes Laravel to fall through;
        // the bootstrap NotFoundHttpException handler maps to 404.
        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('returns 404 for a soft-deleted category', function (): void {
        $cat = Category::query()->where('code', 'spare-parts')->firstOrFail();
        $cat->delete();

        $response = $this->getJson("/api/v1/categories/{$cat->id}");

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });

    it('does not require authentication (public endpoint)', function (): void {
        $cat = Category::query()->first();

        $response = $this->getJson("/api/v1/categories/{$cat->id}");

        $response->assertOk();
    });

    it('returns Arabic translation when Accept-Language: ar', function (): void {
        $cat = Category::query()->where('code', 'commercial')->firstOrFail();

        $response = $this->getJson("/api/v1/categories/{$cat->id}", ['Accept-Language' => 'ar']);

        $response->assertOk();
        expect($response->json('data.name'))->toBe('تجاري');
    });
});
