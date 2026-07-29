<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\User;
use App\Services\Admin\AdminBannerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminBannerService::class);
    $this->actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
});

describe('AdminBannerService::list()', function (): void {
    it('returns active + inactive by default', function (): void {
        Banner::factory()->create();
        Banner::factory()->inactive()->create();

        $page = $this->service->list([], 1, 50);
        expect($page->total())->toBe(2);
    });

    it('filters by is_active=false', function (): void {
        Banner::factory()->create();
        $inactive = Banner::factory()->inactive()->create();

        $page = $this->service->list(['is_active' => false], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->id)->toBe($inactive->id);
    });

    it('includes soft-deleted rows when include_trashed=true', function (): void {
        $deleted = Banner::factory()->softDeleted()->create();
        Banner::factory()->create();

        $without = $this->service->list([], 1, 50);
        expect($without->total())->toBe(1);

        $with = $this->service->list(['include_trashed' => true], 1, 50);
        expect($with->total())->toBe(2);
        $ids = array_map(static fn ($b) => $b->id, $with->items());
        expect($ids)->toContain($deleted->id);
    });

    it('search matches code and translated title', function (): void {
        Banner::factory()->create(['code' => 'needle-banner', 'title' => ['en' => 'Bnr1', 'ar' => 'بانر']]);
        Banner::factory()->create(['code' => 'noise-banner', 'title' => ['en' => 'Bnr2', 'ar' => 'بانر']]);

        expect($this->service->list(['search' => 'needle'], 1, 50)->total())->toBe(1);
        expect($this->service->list(['search' => 'Bnr1'], 1, 50)->total())->toBe(1);
    });

    it('filters by placement', function (): void {
        Banner::factory()->hero()->create();
        Banner::factory()->promo()->create();

        expect($this->service->list(['placement' => 'promo'], 1, 50)->total())->toBe(1);
    });
});

describe('AdminBannerService::find()', function (): void {
    it('returns a soft-deleted banner', function (): void {
        $deleted = Banner::factory()->softDeleted()->create();

        $found = $this->service->find((int) $deleted->id);
        expect($found->id)->toBe($deleted->id);
        expect($found->trashed())->toBeTrue();
    });

    it('throws when id is missing', function (): void {
        $this->service->find(99999999);
    })->throws(ModelNotFoundException::class);
});

describe('AdminBannerService::create()', function (): void {
    it('persists the banner and writes a `banner.admin_created` activity entry', function (): void {
        $banner = $this->service->create([
            'code' => 'service-create-1',
            'placement' => 'hero',
            'image_url' => 'https://cdn.pwt.test/banners/s.webp',
            'title' => ['en' => 'EN', 'ar' => 'AR'],
            'is_active' => true,
        ], $this->actor);

        expect($banner->code)->toBe('service-create-1');
        $this->assertDatabaseHas('banners', ['code' => 'service-create-1']);

        $entry = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($this->actor->id);
    });
});

describe('AdminBannerService::update()', function (): void {
    it('merges single-locale title with stored translations (fallback)', function (): void {
        $banner = Banner::factory()->create([
            'title' => ['en' => 'EN base', 'ar' => 'AR base'],
        ]);

        $updated = $this->service->update($banner, [
            'title' => ['en' => 'EN new'],
        ], $this->actor);

        $translations = $updated->getTranslations('title');
        expect($translations['en'])->toBe('EN new');
        expect($translations['ar'])->toBe('AR base');
    });

    it('drops `code` even if passed (immutable)', function (): void {
        $banner = Banner::factory()->create(['code' => 'keep-banner']);

        $this->service->update($banner, [
            'code' => 'attempted',
            'placement' => 'promo',
        ], $this->actor);

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'code' => 'keep-banner']);
    });

    it('emits `banner.admin_updated` activity entry', function (): void {
        $banner = Banner::factory()->create();

        $this->service->update($banner, ['placement' => 'promo'], $this->actor);

        $entry = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_updated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });
});

describe('AdminBannerService::delete()', function (): void {
    it('soft-deletes and emits a `banner.admin_deleted` activity entry', function (): void {
        $banner = Banner::factory()->create();

        $result = $this->service->delete($banner, $this->actor);

        expect($result->trashed())->toBeTrue();
        $this->assertSoftDeleted('banners', ['id' => $banner->id]);

        $entry = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_deleted')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });

    it('is idempotent on an already-trashed banner (no second log entry)', function (): void {
        $banner = Banner::factory()->softDeleted()->create();
        $deletedAt = $banner->fresh()->deleted_at;
        $countBefore = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_deleted')
            ->count();

        $result = $this->service->delete($banner, $this->actor);

        expect($result->trashed())->toBeTrue();
        expect($result->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();

        $countAfter = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_deleted')
            ->count();
        expect($countAfter)->toBe($countBefore);
    });
});
