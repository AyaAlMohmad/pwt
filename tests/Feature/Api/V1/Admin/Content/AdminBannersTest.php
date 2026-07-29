<?php

declare(strict_types=1);

use App\Enums\BannerPlacement;
use App\Enums\UserRole;
use App\Models\Banner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Banners — feature tests (Phase 20)
|--------------------------------------------------------------------------
|
| Covers GET, POST, PUT, DELETE /api/v1/admin/banners[/{id}].
|
| Pipeline: MySQL `pwt_testing` because the admin banner list service
| uses `JSON_UNQUOTE(JSON_EXTRACT(...))` on the translatable title column.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function bannerAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/banners', function (): void {
    it('returns 200 with the full list (active + inactive) for super_admin', function (): void {
        [, $bearer] = bannerAdminBearer();
        Banner::factory()->create(['code' => 'active-one']);
        Banner::factory()->inactive()->create(['code' => 'hidden-one']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'code', 'placement', 'title', 'subtitle',
                        'image_url', 'image_url_ar', 'cta_label', 'cta_url',
                        'starts_at', 'ends_at', 'display_order', 'is_active',
                        'status', 'deleted_at', 'created_at', 'updated_at',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('active-one')->toContain('hidden-one');
    });

    it('filters by placement', function (): void {
        [, $bearer] = bannerAdminBearer();
        Banner::factory()->hero()->create(['code' => 'hero-1']);
        Banner::factory()->promo()->create(['code' => 'promo-1']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners?placement=promo')
            ->assertOk();

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('promo-1')->not->toContain('hero-1');
    });

    it('allows sales role to read the list (200)', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Sales->value);
        Banner::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners')
            ->assertOk();
    });

    it('rejects operations role with 403', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('rejects customer tokens with 403 ERR_FORBIDDEN', function (): void {
        $customer = User::factory()->customer()->create();
        $bearer = 'Bearer '.$customer->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 when no token is sent', function (): void {
        $this->getJson('/api/v1/admin/banners')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });

    it('includes trashed banners when include_trashed=true', function (): void {
        [, $bearer] = bannerAdminBearer();
        Banner::factory()->create(['code' => 'live']);
        Banner::factory()->softDeleted()->create(['code' => 'trashed-one']);

        $without = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners')->assertOk();
        $codesWithout = array_map(static fn ($r) => $r['code'], $without->json('data'));
        expect($codesWithout)->not->toContain('trashed-one');

        $with = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners?include_trashed=1')->assertOk();
        $codesWith = array_map(static fn ($r) => $r['code'], $with->json('data'));
        expect($codesWith)->toContain('trashed-one');
    });
});

describe('GET /api/v1/admin/banners/{id}', function (): void {
    it('returns a banner with raw bilingual JSON', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create([
            'code' => 'show-1',
            'title' => ['en' => 'Hello', 'ar' => 'مرحبا'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/banners/{$banner->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $banner->id)
            ->assertJsonPath('data.code', 'show-1')
            ->assertJsonPath('data.title.en', 'Hello')
            ->assertJsonPath('data.title.ar', 'مرحبا');
    });

    it('returns a soft-deleted banner via withTrashed binding', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->softDeleted()->create(['code' => 'trash-show']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/banners/{$banner->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $banner->id)
            ->assertJsonPath('data.status', 'trashed');
        expect($response->json('data.deleted_at'))->not->toBeNull();
    });

    it('returns 404 for a missing id', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/banners/9999999')
            ->assertStatus(404);
    });

    it('returns 401 when unauthenticated', function (): void {
        $banner = Banner::factory()->create();

        $this->getJson("/api/v1/admin/banners/{$banner->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('POST /api/v1/admin/banners', function (): void {
    it('creates with bilingual payload and writes an activity entry', function (): void {
        [$admin, $bearer] = bannerAdminBearer();

        $payload = [
            'code' => 'summer-hero',
            'placement' => BannerPlacement::Hero->value,
            'title' => ['en' => 'Summer Sale', 'ar' => 'تخفيضات الصيف'],
            'subtitle' => ['en' => 'Buy now', 'ar' => 'اشتر الآن'],
            'image_url' => 'https://cdn.pwt.test/banners/summer.webp',
            'image_url_ar' => 'https://cdn.pwt.test/banners/summer-ar.webp',
            'cta_label' => ['en' => 'Shop', 'ar' => 'تسوق'],
            'cta_url' => 'https://pwt.test/c/summer',
            'display_order' => 1,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'summer-hero')
            ->assertJsonPath('data.placement', 'hero')
            ->assertJsonPath('data.title.en', 'Summer Sale')
            ->assertJsonPath('data.title.ar', 'تخفيضات الصيف')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('banners', ['code' => 'summer-hero']);

        $entry = Activity::query()
            ->where('log_name', 'admin.banners')
            ->where('event', 'banner.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id);
    });

    it('returns 422 on duplicate code', function (): void {
        [, $bearer] = bannerAdminBearer();
        Banner::factory()->create(['code' => 'dup-banner']);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'dup-banner',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/x.webp',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['code']]);
    });

    it('returns 422 when required fields are missing', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['code', 'placement', 'image_url']]);
    });

    it('returns 422 when ends_at <= starts_at', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'bad-window',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/x.webp',
                'starts_at' => '2026-06-01T00:00:00+04:00',
                'ends_at' => '2026-05-01T00:00:00+04:00',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ends_at']]);
    });

    it('returns 422 for invalid placement', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'bad-place',
                'placement' => 'nowhere',
                'image_url' => 'https://cdn.pwt.test/banners/x.webp',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['placement']]);
    });

    it('allows sales role to create (200/201)', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'sales-can',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/sales.webp',
            ])
            ->assertCreated();
    });

    it('rejects operations role with 403', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'ops-no',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/o.webp',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/banners', [
                'code' => 'support-no',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/s.webp',
            ])
            ->assertStatus(403);
    });

    it('returns 401 unauthenticated', function (): void {
        $this->postJson('/api/v1/admin/banners', [
            'code' => 'unauth',
            'placement' => 'hero',
            'image_url' => 'https://cdn.pwt.test/banners/u.webp',
        ])->assertStatus(401);
    });
});

describe('PUT /api/v1/admin/banners/{id}', function (): void {
    it('updates fields and returns 200', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create([
            'code' => 'before-code',
            'title' => ['en' => 'Old EN', 'ar' => 'قديم'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", [
                'placement' => 'promo',
                'title' => ['en' => 'New EN', 'ar' => 'جديد'],
                'image_url' => 'https://cdn.pwt.test/banners/updated.webp',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.placement', 'promo')
            ->assertJsonPath('data.title.en', 'New EN')
            ->assertJsonPath('data.title.ar', 'جديد');
    });

    it('silently drops `code` because it is immutable', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create(['code' => 'stable-code']);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", [
                'code' => 'attempted-rename',
                'placement' => 'hero',
                'image_url' => 'https://cdn.pwt.test/banners/x.webp',
            ])->assertOk();

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'code' => 'stable-code']);
        $this->assertDatabaseMissing('banners', ['code' => 'attempted-rename']);
    });

    it('merges single-locale title with stored translation (fallback)', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create([
            'title' => ['en' => 'EN base', 'ar' => 'AR base'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", [
                'title' => ['en' => 'EN updated'],
            ])->assertOk();

        // EN replaced, AR preserved
        expect($response->json('data.title.en'))->toBe('EN updated');
        expect($response->json('data.title.ar'))->toBe('AR base');
    });

    it('returns 422 when ends_at <= starts_at on PUT', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", [
                'starts_at' => '2026-08-01T00:00:00+04:00',
                'ends_at' => '2026-07-01T00:00:00+04:00',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ends_at']]);
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/banners/9999999', [
                'placement' => 'hero',
            ])->assertStatus(404);
    });

    it('allows sales role to update', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Sales->value);
        $banner = Banner::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", [
                'placement' => 'promo',
            ])->assertOk();
    });

    it('rejects operations role on update with 403', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Operations->value);
        $banner = Banner::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/banners/{$banner->id}", ['placement' => 'promo'])
            ->assertStatus(403);
    });
});

describe('DELETE /api/v1/admin/banners/{id}', function (): void {
    it('soft-deletes the banner for super_admin and returns 200', function (): void {
        [, $bearer] = bannerAdminBearer();
        $banner = Banner::factory()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/banners/{$banner->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $banner->id);

        expect($response->json('data.deleted_at'))->not->toBeNull();
        $this->assertSoftDeleted('banners', ['id' => $banner->id]);
    });

    it('returns 403 for sales role (super_admin only delete)', function (): void {
        [, $bearer] = bannerAdminBearer(UserRole::Sales->value);
        $banner = Banner::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/banners/{$banner->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'deleted_at' => null]);
    });

    it('returns 403 for operations and support', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            [, $bearer] = bannerAdminBearer($role);
            $banner = Banner::factory()->create();

            $this->withHeader('Authorization', $bearer)
                ->deleteJson("/api/v1/admin/banners/{$banner->id}")
                ->assertStatus(403);
        }
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = bannerAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson('/api/v1/admin/banners/9999999')
            ->assertStatus(404);
    });

    it('returns 401 unauthenticated', function (): void {
        $banner = Banner::factory()->create();
        $this->deleteJson("/api/v1/admin/banners/{$banner->id}")
            ->assertStatus(401);
    });
});
