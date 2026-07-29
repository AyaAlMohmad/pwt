<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Promotions — feature tests (Phase 20)
|--------------------------------------------------------------------------
|
| Covers GET, POST, PUT, DELETE /api/v1/admin/promotions[/{id}].
| Pipeline: MySQL `pwt_testing`.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function promotionAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/promotions', function (): void {
    it('returns 200 with active + expired + inactive', function (): void {
        [, $bearer] = promotionAdminBearer();
        Promotion::factory()->active()->create(['code' => 'ACTIVE-A']);
        Promotion::factory()->expired()->create(['code' => 'EXPIRED-A']);
        Promotion::factory()->inactive()->create(['code' => 'OFF-A']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id', 'code', 'name', 'description', 'type', 'value',
                        'max_discount_amount', 'currency', 'min_order_amount',
                        'starts_at', 'ends_at', 'per_user_limit', 'total_limit',
                        'applies_to', 'is_active', 'status', 'redemptions_count',
                        'deleted_at', 'created_at', 'updated_at',
                    ],
                ],
                'meta' => ['pagination'],
            ]);

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('ACTIVE-A')->toContain('EXPIRED-A')->toContain('OFF-A');
    });

    it('filters by status=expired', function (): void {
        [, $bearer] = promotionAdminBearer();
        Promotion::factory()->active()->create(['code' => 'STILL-LIVE']);
        Promotion::factory()->expired()->create(['code' => 'GONE-X']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions?status=expired')
            ->assertOk();

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('GONE-X')->not->toContain('STILL-LIVE');
    });

    it('rejects operations role with 403', function (): void {
        [, $bearer] = promotionAdminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = promotionAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions')
            ->assertStatus(403);
    });

    it('rejects customer tokens with 403', function (): void {
        $customer = User::factory()->customer()->create();
        $bearer = 'Bearer '.$customer->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions')
            ->assertStatus(403);
    });

    it('returns 401 unauthenticated', function (): void {
        $this->getJson('/api/v1/admin/promotions')
            ->assertStatus(401);
    });
});

describe('GET /api/v1/admin/promotions/{id}', function (): void {
    it('returns detail with products array when applies_to=products', function (): void {
        [, $bearer] = promotionAdminBearer();
        $category = Category::factory()->create();
        $p1 = Product::factory()->forCategory($category)->create();
        $p2 = Product::factory()->forCategory($category)->create();
        $promotion = Promotion::factory()->appliesToProducts()->create(['code' => 'DETAIL-P']);
        $promotion->products()->sync([$p1->id, $p2->id]);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/promotions/{$promotion->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $promotion->id)
            ->assertJsonPath('data.applies_to', 'products')
            ->assertJsonStructure([
                'data' => [
                    'products' => ['*' => ['id', 'code', 'name']],
                    'total_amount_redeemed',
                    'unique_users_count',
                ],
            ]);

        $productIds = array_map(static fn ($p) => $p['id'], $response->json('data.products'));
        expect($productIds)->toContain($p1->id)->toContain($p2->id);
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = promotionAdminBearer();
        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/promotions/9999999')
            ->assertStatus(404);
    });

    it('returns soft-deleted promotion via withTrashed binding', function (): void {
        [, $bearer] = promotionAdminBearer();
        $promotion = Promotion::factory()->softDeleted()->create(['code' => 'TRASH-P']);

        $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'trashed');
    });
});

describe('POST /api/v1/admin/promotions', function (): void {
    it('creates percent + max_discount_amount and writes activity entry', function (): void {
        [$admin, $bearer] = promotionAdminBearer();

        $payload = [
            'code' => 'percent-cap',
            'name' => ['en' => 'Spring 20', 'ar' => 'ربيع'],
            'type' => 'percent',
            'value' => '20.00',
            'max_discount_amount' => '50.00',
            'applies_to' => 'all',
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'PERCENT-CAP') // uppercased server-side
            ->assertJsonPath('data.type', 'percent')
            ->assertJsonPath('data.applies_to', 'all');

        $this->assertDatabaseHas('promotions', ['code' => 'PERCENT-CAP']);

        $entry = Activity::query()
            ->where('log_name', 'admin.promotions')
            ->where('event', 'promotion.admin_created')
            ->latest('id')
            ->first();
        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id);
    });

    it('creates a fixed-amount promotion with currency', function (): void {
        [, $bearer] = promotionAdminBearer();

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'fixed-50',
                'name' => ['en' => 'AED 50', 'ar' => '٥٠ درهم'],
                'type' => 'fixed',
                'value' => '50.00',
                'currency' => 'aed', // lowercased — service normalises
                'applies_to' => 'all',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.currency', 'AED');
    });

    it('returns 422 when fixed promotion lacks currency', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'no-currency',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'fixed',
                'value' => '25.00',
                'applies_to' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['currency']]);
    });

    it('returns 422 when percent > 100', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'too-much',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '120.00',
                'max_discount_amount' => '50.00',
                'applies_to' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['value']]);
    });

    it('returns 422 when fixed promotion includes max_discount_amount', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'fixed-bad',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'fixed',
                'value' => '25.00',
                'currency' => 'AED',
                'max_discount_amount' => '100.00',
                'applies_to' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['max_discount_amount']]);
    });

    it('returns 422 when ends_at <= starts_at', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'window-bad',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '10.00',
                'max_discount_amount' => '50.00',
                'applies_to' => 'all',
                'starts_at' => '2026-08-01T00:00:00+04:00',
                'ends_at' => '2026-07-01T00:00:00+04:00',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ends_at']]);
    });

    it('returns 422 when code is missing', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '10.00',
                'max_discount_amount' => '50.00',
                'applies_to' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    });

    it('returns 422 on duplicate code', function (): void {
        [, $bearer] = promotionAdminBearer();
        Promotion::factory()->create(['code' => 'DUPER']);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'duper',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '10.00',
                'max_discount_amount' => '50.00',
                'applies_to' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    });

    it('requires product_ids when applies_to=products', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'p-missing',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '10.00',
                'max_discount_amount' => '50.00',
                'applies_to' => 'products',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['product_ids']]);
    });

    it('allows sales role to create', function (): void {
        [, $bearer] = promotionAdminBearer(UserRole::Sales->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/promotions', [
                'code' => 'sales-promo',
                'name' => ['en' => 'X', 'ar' => 'X'],
                'type' => 'percent',
                'value' => '5.00',
                'max_discount_amount' => '10.00',
                'applies_to' => 'all',
            ])
            ->assertCreated();
    });

    it('rejects operations and support roles with 403', function (): void {
        foreach ([UserRole::Operations->value, UserRole::Support->value] as $role) {
            [, $bearer] = promotionAdminBearer($role);
            $this->withHeader('Authorization', $bearer)
                ->postJson('/api/v1/admin/promotions', [
                    'code' => 'r-'.$role,
                    'name' => ['en' => 'X', 'ar' => 'X'],
                    'type' => 'percent',
                    'value' => '5.00',
                    'max_discount_amount' => '10.00',
                    'applies_to' => 'all',
                ])
                ->assertStatus(403);
        }
    });
});

describe('PUT /api/v1/admin/promotions/{id}', function (): void {
    it('silently drops `code` because it is immutable', function (): void {
        [, $bearer] = promotionAdminBearer();
        $promotion = Promotion::factory()->create(['code' => 'KEEP-ME']);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/promotions/{$promotion->id}", [
                'code' => 'renamed',
                'is_active' => false,
            ])->assertOk();

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'code' => 'KEEP-ME']);
        $this->assertDatabaseMissing('promotions', ['code' => 'RENAMED']);
    });

    it('replaces the pivot when applies_to changes from products to all', function (): void {
        [, $bearer] = promotionAdminBearer();
        $category = Category::factory()->create();
        $p1 = Product::factory()->forCategory($category)->create();
        $promotion = Promotion::factory()->appliesToProducts()->create();
        $promotion->products()->sync([$p1->id]);

        expect($promotion->products()->count())->toBe(1);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/promotions/{$promotion->id}", [
                'applies_to' => 'all',
            ])->assertOk();

        expect($promotion->fresh()->products()->count())->toBe(0);
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->putJson('/api/v1/admin/promotions/9999999', ['is_active' => false])
            ->assertStatus(404);
    });

    it('rejects operations role with 403', function (): void {
        [, $bearer] = promotionAdminBearer(UserRole::Operations->value);
        $promotion = Promotion::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/promotions/{$promotion->id}", ['is_active' => false])
            ->assertStatus(403);
    });
});

describe('DELETE /api/v1/admin/promotions/{id}', function (): void {
    it('soft-deletes a promotion with no redemptions for super_admin', function (): void {
        [, $bearer] = promotionAdminBearer();
        $promotion = Promotion::factory()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/promotions/{$promotion->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $promotion->id);

        expect($response->json('data.deleted_at'))->not->toBeNull();
        $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);
    });

    it('returns 422 ERR_PROMOTION_HAS_REDEMPTIONS when redemptions exist', function (): void {
        [, $bearer] = promotionAdminBearer();
        $user = User::factory()->customer()->create();
        $promotion = Promotion::factory()->create();
        PromotionRedemption::factory()->forPromotion($promotion)->forUser($user)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_PROMOTION_HAS_REDEMPTIONS')
            ->assertJsonStructure(['errors' => ['redemptions_count']]);

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'deleted_at' => null]);
    });

    it('returns 403 for sales role (super_admin only)', function (): void {
        [, $bearer] = promotionAdminBearer(UserRole::Sales->value);
        $promotion = Promotion::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'deleted_at' => null]);
    });

    it('returns 404 for missing id', function (): void {
        [, $bearer] = promotionAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson('/api/v1/admin/promotions/9999999')
            ->assertStatus(404);
    });

    it('returns 401 unauthenticated', function (): void {
        $promotion = Promotion::factory()->create();
        $this->deleteJson("/api/v1/admin/promotions/{$promotion->id}")
            ->assertStatus(401);
    });
});
