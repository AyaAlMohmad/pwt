<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Exceptions\Admin\Promotions\PromotionHasRedemptionsException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Admin\AdminPromotionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminPromotionService::class);
    $this->actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
});

describe('AdminPromotionService::list()', function (): void {
    it('includes active + expired + inactive promotions', function (): void {
        Promotion::factory()->active()->create();
        Promotion::factory()->expired()->create();
        Promotion::factory()->inactive()->create();

        $page = $this->service->list([], 1, 50);
        expect($page->total())->toBe(3);
    });

    it('filters by status=expired', function (): void {
        Promotion::factory()->active()->create();
        $expired = Promotion::factory()->expired()->create();

        $page = $this->service->list(['status' => 'expired'], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($expired->id);
    });

    it('filters by status=upcoming', function (): void {
        Promotion::factory()->active()->create();
        $upcoming = Promotion::factory()->upcoming()->create();

        $page = $this->service->list(['status' => 'upcoming'], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($upcoming->id);
    });

    it('filters by status=inactive', function (): void {
        Promotion::factory()->active()->create();
        $inactive = Promotion::factory()->inactive()->create();

        $page = $this->service->list(['status' => 'inactive'], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($inactive->id);
    });

    it('includes redemptions_count via withCount', function (): void {
        $promotion = Promotion::factory()->create();
        $user = User::factory()->customer()->create();
        PromotionRedemption::factory()->forPromotion($promotion)->forUser($user)->create();
        PromotionRedemption::factory()->forPromotion($promotion)->forUser($user)->create();

        $page = $this->service->list([], 1, 50);
        /** @var Promotion $row */
        $row = $page->items()[0];
        expect((int) $row->redemptions_count)->toBe(2);
    });

    it('includes trashed when include_trashed=true', function (): void {
        Promotion::factory()->create();
        $trashed = Promotion::factory()->softDeleted()->create();

        $without = $this->service->list([], 1, 50);
        expect($without->total())->toBe(1);
        $with = $this->service->list(['include_trashed' => true], 1, 50);
        expect($with->total())->toBe(2);
        $ids = array_map(static fn ($p) => $p->id, $with->items());
        expect($ids)->toContain($trashed->id);
    });
});

describe('AdminPromotionService::find()', function (): void {
    it('eager-loads products and computes aggregates', function (): void {
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();
        $promotion = Promotion::factory()->appliesToProducts()->create();
        $promotion->products()->sync([$product->id]);

        $user = User::factory()->customer()->create();
        PromotionRedemption::factory()
            ->forPromotion($promotion)
            ->forUser($user)
            ->withAmount(25.00, 'AED')
            ->create();

        $found = $this->service->find((int) $promotion->id);

        expect($found->relationLoaded('products'))->toBeTrue();
        expect($found->getAttribute('total_amount_redeemed'))->toBe('25.00');
        expect((int) $found->getAttribute('unique_users_count'))->toBe(1);
    });
});

describe('AdminPromotionService::create()', function (): void {
    it('uppercases code on insert and logs `promotion.admin_created`', function (): void {
        $promotion = $this->service->create([
            'code' => 'lowercase',
            'name' => ['en' => 'X', 'ar' => 'X'],
            'type' => 'percent',
            'value' => '10.00',
            'max_discount_amount' => '50.00',
            'applies_to' => 'all',
        ], $this->actor);

        expect($promotion->code)->toBe('LOWERCASE');

        $entry = Activity::query()
            ->where('log_name', 'admin.promotions')
            ->where('event', 'promotion.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($this->actor->id);
    });

    it('syncs product pivot when applies_to=products', function (): void {
        $category = Category::factory()->create();
        $p1 = Product::factory()->forCategory($category)->create();
        $p2 = Product::factory()->forCategory($category)->create();

        $promotion = $this->service->create([
            'code' => 'with-products',
            'name' => ['en' => 'X', 'ar' => 'X'],
            'type' => 'percent',
            'value' => '10.00',
            'max_discount_amount' => '50.00',
            'applies_to' => 'products',
            'product_ids' => [$p1->id, $p2->id],
        ], $this->actor);

        expect($promotion->products()->count())->toBe(2);
    });
});

describe('AdminPromotionService::update()', function (): void {
    it('drops `code` even if passed (immutable)', function (): void {
        $promotion = Promotion::factory()->create(['code' => 'KEEP']);

        $this->service->update($promotion, [
            'code' => 'attempted',
            'is_active' => false,
        ], $this->actor);

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'code' => 'KEEP']);
    });

    it('logs `promotion.admin_updated`', function (): void {
        $promotion = Promotion::factory()->create();

        $this->service->update($promotion, ['is_active' => false], $this->actor);

        $entry = Activity::query()
            ->where('log_name', 'admin.promotions')
            ->where('event', 'promotion.admin_updated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });

    it('purges pivots when applies_to switches to all', function (): void {
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();
        $promotion = Promotion::factory()->appliesToProducts()->create();
        $promotion->products()->sync([$product->id]);

        $this->service->update($promotion, ['applies_to' => 'all'], $this->actor);

        expect($promotion->fresh()->products()->count())->toBe(0);
    });
});

describe('AdminPromotionService::delete()', function (): void {
    it('soft-deletes with no redemptions and logs activity', function (): void {
        $promotion = Promotion::factory()->create();

        $result = $this->service->delete($promotion, $this->actor);

        expect($result->trashed())->toBeTrue();
        $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);

        $entry = Activity::query()
            ->where('log_name', 'admin.promotions')
            ->where('event', 'promotion.admin_deleted')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });

    it('throws PromotionHasRedemptionsException when redemptions exist', function (): void {
        $promotion = Promotion::factory()->create();
        $user = User::factory()->customer()->create();
        PromotionRedemption::factory()->forPromotion($promotion)->forUser($user)->create();

        $this->service->delete($promotion, $this->actor);
    })->throws(PromotionHasRedemptionsException::class);

    it('does not soft-delete when redemptions exist (transaction rollback)', function (): void {
        $promotion = Promotion::factory()->create();
        $user = User::factory()->customer()->create();
        PromotionRedemption::factory()->forPromotion($promotion)->forUser($user)->create();

        try {
            $this->service->delete($promotion, $this->actor);
        } catch (PromotionHasRedemptionsException) {
            // expected
        }

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'deleted_at' => null]);
    });

    it('is idempotent on an already-trashed promotion', function (): void {
        $promotion = Promotion::factory()->softDeleted()->create();
        $deletedAt = $promotion->fresh()->deleted_at;

        $result = $this->service->delete($promotion, $this->actor);

        expect($result->trashed())->toBeTrue();
        expect($result->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();
    });
});
