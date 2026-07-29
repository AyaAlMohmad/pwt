<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\Admin\Catalog\CategoryHasProductsException;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\AdminCategoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminCategoryService::class);
    $this->actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
});

describe('AdminCategoryService::list()', function (): void {
    it('returns active + inactive categories by default', function (): void {
        Category::factory()->create();
        Category::factory()->inactive()->create();

        $page = $this->service->list([], 1, 50);
        expect($page->total())->toBe(2);
    });

    it('filters by is_active=false', function (): void {
        Category::factory()->create();
        $inactive = Category::factory()->inactive()->create();

        $page = $this->service->list(['is_active' => false], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($inactive->id);
    });

    it('includes soft-deleted rows when include_trashed=true', function (): void {
        $deleted = Category::factory()->softDeleted()->create();
        Category::factory()->create();

        $without = $this->service->list([], 1, 50);
        expect($without->total())->toBe(1);

        $with = $this->service->list(['include_trashed' => true], 1, 50);
        expect($with->total())->toBe(2);
        $ids = array_map(static fn ($c) => $c->id, $with->items());
        expect($ids)->toContain($deleted->id);
    });

    it('applies search across code and translated name', function (): void {
        Category::factory()->create(['code' => 'needle-cat', 'name' => ['en' => 'Cat1', 'ar' => 'الفئة']]);
        Category::factory()->create(['code' => 'noise-cat', 'name' => ['en' => 'Cat2', 'ar' => 'الفئة']]);

        expect($this->service->list(['search' => 'needle'], 1, 50)->total())->toBe(1);
        expect($this->service->list(['search' => 'Cat1'], 1, 50)->total())->toBe(1);
    });
});

describe('AdminCategoryService::create()', function (): void {
    it('persists the category and writes a `category.created` activity entry (Spatie trait)', function (): void {
        $category = $this->service->create([
            'code' => 'unit-test-cat',
            'name' => ['en' => 'Unit Test', 'ar' => 'اختبار'],
            'is_active' => true,
        ], $this->actor);

        expect($category->code)->toBe('unit-test-cat');
        $this->assertDatabaseHas('categories', ['code' => 'unit-test-cat']);

        $entry = Activity::query()
            ->where('log_name', 'category')
            ->where('event', 'created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });
});

describe('AdminCategoryService::deactivate()', function (): void {
    it('soft-deletes a category with no products', function (): void {
        $category = Category::factory()->create();

        $result = $this->service->deactivate($category, $this->actor);

        expect($result->trashed())->toBeTrue();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    });

    it('throws CategoryHasProductsException when the category still has products', function (): void {
        $category = Category::factory()->create();
        Product::factory()->forCategory($category)->create();

        $this->service->deactivate($category, $this->actor);
    })->throws(CategoryHasProductsException::class);

    it('is idempotent on an already-trashed category', function (): void {
        $category = Category::factory()->softDeleted()->create();
        $deletedAt = $category->fresh()->deleted_at;

        $result = $this->service->deactivate($category, $this->actor);

        expect($result->trashed())->toBeTrue();
        expect($result->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();
    });
});

describe('AdminCategoryService::update()', function (): void {
    it('updates the name and emits a Spatie `updated` activity row', function (): void {
        $category = Category::factory()->create(['code' => 'before', 'name' => ['en' => 'Old', 'ar' => 'قديم']]);

        $updated = $this->service->update($category, [
            'code' => 'after',
            'name' => ['en' => 'New', 'ar' => 'جديد'],
            'is_active' => true,
        ], $this->actor);

        expect($updated->code)->toBe('after');

        $entry = Activity::query()
            ->where('log_name', 'category')
            ->where('event', 'updated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });
});
