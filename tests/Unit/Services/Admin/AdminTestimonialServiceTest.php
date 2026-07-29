<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Admin\AdminTestimonialService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(AdminTestimonialService::class);
    $this->actor = User::factory()->admin(UserRole::SuperAdmin->value)->create();
});

describe('AdminTestimonialService::list()', function (): void {
    it('returns active + inactive by default', function (): void {
        Testimonial::factory()->create();
        Testimonial::factory()->inactive()->create();

        $page = $this->service->list([], 1, 50);
        expect($page->total())->toBe(2);
    });

    it('filters by rating', function (): void {
        Testimonial::factory()->rating(5)->create();
        Testimonial::factory()->rating(3)->create();

        $page = $this->service->list(['rating' => 5], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->rating)->toBe(5);
    });

    it('filters by rating_min and rating_max', function (): void {
        Testimonial::factory()->rating(1)->create();
        Testimonial::factory()->rating(3)->create();
        Testimonial::factory()->rating(5)->create();

        $page = $this->service->list(['rating_min' => 2, 'rating_max' => 4], 1, 50);
        expect($page->total())->toBe(1);
        expect($page->items()[0]->rating)->toBe(3);
    });

    it('search matches name, company, and quote translations', function (): void {
        Testimonial::factory()->create(['name' => 'Alice Needle']);
        Testimonial::factory()->create(['name' => 'Bob', 'company' => 'NeedleCo']);
        Testimonial::factory()->create(['name' => 'C', 'quote' => ['en' => 'a Needle quote', 'ar' => 'X']]);
        Testimonial::factory()->create(['name' => 'Other', 'company' => 'OtherCo', 'quote' => ['en' => 'X', 'ar' => 'X']]);

        expect($this->service->list(['search' => 'Needle'], 1, 50)->total())->toBe(3);
    });

    it('includes trashed when include_trashed=true', function (): void {
        Testimonial::factory()->create();
        $trashed = Testimonial::factory()->softDeleted()->create();

        $without = $this->service->list([], 1, 50);
        expect($without->total())->toBe(1);
        $with = $this->service->list(['include_trashed' => true], 1, 50);
        expect($with->total())->toBe(2);
        $ids = array_map(static fn ($t) => $t->id, $with->items());
        expect($ids)->toContain($trashed->id);
    });
});

describe('AdminTestimonialService::find()', function (): void {
    it('returns a soft-deleted testimonial', function (): void {
        $t = Testimonial::factory()->softDeleted()->create();
        $found = $this->service->find((int) $t->id);
        expect($found->id)->toBe($t->id);
        expect($found->trashed())->toBeTrue();
    });

    it('throws when id is missing', function (): void {
        $this->service->find(99999999);
    })->throws(ModelNotFoundException::class);
});

describe('AdminTestimonialService::create()', function (): void {
    it('creates and logs activity', function (): void {
        $t = $this->service->create([
            'name' => 'Service Created',
            'rating' => 4,
            'quote' => ['en' => 'good', 'ar' => 'جيد'],
        ], $this->actor);

        expect($t->name)->toBe('Service Created');

        $entry = Activity::query()
            ->where('log_name', 'admin.testimonials')
            ->where('event', 'testimonial.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($this->actor->id);
    });
});

describe('AdminTestimonialService::update()', function (): void {
    it('updates and logs activity', function (): void {
        $t = Testimonial::factory()->create(['name' => 'Old']);

        $updated = $this->service->update($t, ['name' => 'New', 'rating' => 5], $this->actor);

        expect($updated->name)->toBe('New');
        expect((int) $updated->rating)->toBe(5);

        $entry = Activity::query()
            ->where('log_name', 'admin.testimonials')
            ->where('event', 'testimonial.admin_updated')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });

    it('merges single-locale quote with stored translations', function (): void {
        $t = Testimonial::factory()->create([
            'quote' => ['en' => 'EN base', 'ar' => 'AR base'],
        ]);

        $updated = $this->service->update($t, [
            'quote' => ['en' => 'EN new'],
        ], $this->actor);

        $translations = $updated->getTranslations('quote');
        expect($translations['en'])->toBe('EN new');
        expect($translations['ar'])->toBe('AR base');
    });
});

describe('AdminTestimonialService::delete()', function (): void {
    it('soft-deletes and logs activity', function (): void {
        $t = Testimonial::factory()->create();

        $result = $this->service->delete($t, $this->actor);

        expect($result->trashed())->toBeTrue();
        $this->assertSoftDeleted('testimonials', ['id' => $t->id]);

        $entry = Activity::query()
            ->where('log_name', 'admin.testimonials')
            ->where('event', 'testimonial.admin_deleted')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull();
    });

    it('is idempotent on an already-trashed testimonial', function (): void {
        $t = Testimonial::factory()->softDeleted()->create();
        $deletedAt = $t->fresh()->deleted_at;

        $result = $this->service->delete($t, $this->actor);

        expect($result->trashed())->toBeTrue();
        expect($result->fresh()->deleted_at->equalTo($deletedAt))->toBeTrue();
    });
});
