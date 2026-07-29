<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\Admin\Catalog\ProductCodeImmutableException;
use App\Exceptions\Admin\Catalog\ProductHasActiveOrdersException;
use App\Models\Category;
use App\Models\File;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Admin\AdminProductService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminProductService::class);
    $this->actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
});

describe('AdminProductService::update() — price replace-all', function (): void {
    it('atomically replaces all price rows (count before/after diverges)', function (): void {
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();

        ProductPrice::factory()->forProduct($product)->buy(1000)->create();
        ProductPrice::factory()->forProduct($product)->rent(50)->create();
        ProductPrice::factory()->forProduct($product)->foc()->create();

        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(3);

        $this->service->update($product, [
            'category_id' => $category->id,
            'name' => ['en' => 'X', 'ar' => 'إكس'],
            'prices' => [
                ['term' => 'buy', 'amount' => 9999.99, 'currency' => 'AED'],
            ],
        ], $this->actor);

        expect(ProductPrice::where('product_id', $product->id)->count())->toBe(1);

        $activity = Activity::query()
            ->where('log_name', 'product.prices')
            ->where('event', 'product.prices_replaced')
            ->latest('id')
            ->first();
        expect($activity)->not->toBeNull();
        expect($activity->properties['before_count'])->toBe(3);
        expect($activity->properties['after_count'])->toBe(1);
    });

    it('throws ProductCodeImmutableException when code is provided', function (): void {
        $product = Product::factory()->create(['code' => 'P-X']);

        $this->service->update($product, [
            'code' => 'P-Y',
            'category_id' => $product->category_id,
            'name' => ['en' => 'X'],
        ], $this->actor);
    })->throws(ProductCodeImmutableException::class);
});

describe('AdminProductService::updateStatus() — activity log', function (): void {
    it('logs a `product.status_changed` activity with before/after diff', function (): void {
        $product = Product::factory()->create(['is_featured' => false]);

        $this->service->updateStatus($product, ['is_featured' => true], $this->actor);

        $entry = Activity::query()
            ->where('event', 'product.status_changed')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->properties['before']['is_featured'])->toBeFalse()
            ->and($entry->properties['after']['is_featured'])->toBeTrue()
            ->and($entry->properties['changed_fields'])->toContain('is_featured');
    });

    it('does NOT log a status_changed entry when nothing actually changed', function (): void {
        $product = Product::factory()->create(['is_featured' => false]);
        // Capture pre-state count.
        $before = Activity::query()->where('event', 'product.status_changed')->count();

        $this->service->updateStatus($product, ['is_featured' => false], $this->actor);

        $after = Activity::query()->where('event', 'product.status_changed')->count();
        expect($after)->toBe($before);
    });
});

describe('AdminProductService::deactivate() — active orders guard', function (): void {
    it('blocks delete with ProductHasActiveOrdersException when a pending order references the product', function (): void {
        $product = Product::factory()->create();
        $order = Order::factory()->create(['status' => OrderStatus::Pending->value]);
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        $this->service->deactivate($product, $this->actor);
    })->throws(ProductHasActiveOrdersException::class);

    it('allows delete when the only referencing order is completed (terminal)', function (): void {
        $product = Product::factory()->create();
        $order = Order::factory()->completed()->create();
        OrderItem::factory()->forOrder($order)->forProduct($product)->create();

        $result = $this->service->deactivate($product, $this->actor);

        expect($result->trashed())->toBeTrue();
        expect($result->is_active)->toBeFalse();
    });
});

describe('AdminProductService::attachImage() — atomic demotion', function (): void {
    it('demotes the previous primary in the same transaction', function (): void {
        $product = Product::factory()->create();
        $existingFile = File::factory()->forType(FileType::ProductImage)->create();
        $existing = ProductImage::factory()->forProduct($product)->primary()->create([
            'file_id' => $existingFile->id,
        ]);
        $file = File::factory()->forType(FileType::ProductImage)->create();

        $this->service->attachImage($product, [
            'file_id' => $file->id,
            'is_primary' => true,
        ], $this->actor);

        expect($existing->fresh()->is_primary)->toBeFalse();
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'file_id' => $file->id,
            'is_primary' => true,
        ]);

        // Activity event captured.
        $entry = Activity::query()
            ->where('event', 'product.image_attached')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->properties['demoted_previous_primary'])->toBe($existing->id);
    });

    it('is idempotent on the same file_id (update in place)', function (): void {
        $product = Product::factory()->create();
        $file = File::factory()->forType(FileType::ProductImage)->create();

        $first = $this->service->attachImage($product, ['file_id' => $file->id], $this->actor);
        $second = $this->service->attachImage($product, ['file_id' => $file->id], $this->actor);

        expect(ProductImage::where('product_id', $product->id)->count())->toBe(1);
        // Same row returned both times.
        $images = $second->images;
        expect($images)->toHaveCount(1);
    });
});

describe('AdminProductService::detachImage()', function (): void {
    it('hard-deletes the image row and logs a detach activity entry', function (): void {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->forProduct($product)->create();

        $this->service->detachImage($product, $image, $this->actor);

        expect(ProductImage::where('id', $image->id)->exists())->toBeFalse();
        $entry = Activity::query()
            ->where('event', 'product.image_detached')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
        expect($entry->properties['image_id'])->toBe($image->id);
    });
});

describe('AdminProductService::list() filters', function (): void {
    it('filters by category_id and stock_status', function (): void {
        $catA = Category::factory()->create();
        $catB = Category::factory()->create();
        Product::factory()->forCategory($catA)->create();
        Product::factory()->forCategory($catA)->outOfStock()->create();
        Product::factory()->forCategory($catB)->create();

        $page = $this->service->list(['category_id' => $catA->id], 1, 50);
        expect($page->total())->toBe(2);

        $page = $this->service->list(['stock_status' => 'out_of_stock'], 1, 50);
        expect($page->total())->toBe(1);
    });
});
