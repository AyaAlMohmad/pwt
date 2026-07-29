<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Categories — feature tests
|--------------------------------------------------------------------------
|
| Covers GET, POST, PUT, DELETE /api/v1/admin/categories[/{id}].
|
| Pipeline: MySQL `pwt_testing` (admin catalog reuses the JSON_EXTRACT
| read path for translatable name search — see tests/Pest.php).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * Create an admin + a Bearer token in a single helper.
 *
 * @return array{0: User, 1: string}
 */
function categoryAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('GET /api/v1/admin/categories', function (): void {
    it('returns 200 with the full list (including inactive) for super_admin', function (): void {
        [, $bearer] = categoryAdminBearer();
        Category::factory()->create(['code' => 'active-cat']);
        Category::factory()->inactive()->create(['code' => 'hidden-cat']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/categories');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id', 'code', 'name', 'image_url', 'parent_id',
                        'display_order', 'is_active', 'deleted_at',
                        'created_at', 'updated_at', 'products_count',
                    ],
                ],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total']],
            ]);

        $codes = array_map(static fn ($r) => $r['code'], $response->json('data'));
        expect($codes)->toContain('active-cat')->toContain('hidden-cat');
    });

    it('filters by is_active=false', function (): void {
        [, $bearer] = categoryAdminBearer();
        Category::factory()->create(['code' => 'visible']);
        Category::factory()->inactive()->create(['code' => 'hidden']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/categories?is_active=0')
            ->assertOk();

        $rows = $response->json('data');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['code'])->toBe('hidden');
        expect($rows[0]['is_active'])->toBeFalse();
    });

    it('allows operations role to read the list (200)', function (): void {
        [, $bearer] = categoryAdminBearer(UserRole::Operations->value);
        Category::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/categories')
            ->assertOk();
    });

    it('rejects customer tokens with 403 ERR_FORBIDDEN', function (): void {
        $customer = User::factory()->customer()->create();
        $bearer = 'Bearer '.$customer->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/categories')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 when no token is sent', function (): void {
        $this->getJson('/api/v1/admin/categories')
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});

describe('GET /api/v1/admin/categories/{id}', function (): void {
    it('returns an inactive category for super_admin', function (): void {
        [, $bearer] = categoryAdminBearer();
        $category = Category::factory()->inactive()->create(['code' => 'hidden-show']);

        $response = $this->withHeader('Authorization', $bearer)
            ->getJson("/api/v1/admin/categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.code', 'hidden-show');
    });

    it('returns 404 for a missing id', function (): void {
        [, $bearer] = categoryAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->getJson('/api/v1/admin/categories/9999999')
            ->assertStatus(404);
    });
});

describe('POST /api/v1/admin/categories', function (): void {
    it('creates with bilingual payload, returns 201, and writes a category.created activity entry', function (): void {
        [$admin, $bearer] = categoryAdminBearer();

        $payload = [
            'code' => 'new-counter-top',
            'name' => ['en' => 'Counter-top', 'ar' => 'فلاتر مرتفعة'],
            'description' => ['en' => 'A description', 'ar' => 'وصف'],
            'display_order' => 5,
            'is_active' => true,
        ];

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/categories', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'new-counter-top')
            ->assertJsonPath('data.name.en', 'Counter-top')
            ->assertJsonPath('data.name.ar', 'فلاتر مرتفعة')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.display_order', 5);

        $this->assertDatabaseHas('categories', ['code' => 'new-counter-top']);

        // Spatie LogsActivity fires `created` automatically.
        $entry = Activity::query()
            ->where('log_name', 'category')
            ->where('event', 'created')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id);
    });

    it('returns 422 on duplicate code', function (): void {
        [, $bearer] = categoryAdminBearer();
        Category::factory()->create(['code' => 'dup-code']);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/categories', [
                'code' => 'dup-code',
                'name' => ['en' => 'Dup', 'ar' => 'مكرر'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['code']]);
    });

    it('returns 422 when name.en is missing', function (): void {
        [, $bearer] = categoryAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/categories', [
                'code' => 'no-en',
                'name' => ['ar' => 'فقط عربي'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION')
            ->assertJsonStructure(['errors' => ['name.en']]);
    });

    it('rejects support role with 403 (read-only)', function (): void {
        [, $bearer] = categoryAdminBearer(UserRole::Support->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/categories', [
                'code' => 'support-cat',
                'name' => ['en' => 'Support', 'ar' => 'دعم'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('rejects operations role with 403 (read-only)', function (): void {
        [, $bearer] = categoryAdminBearer(UserRole::Operations->value);

        $this->withHeader('Authorization', $bearer)
            ->postJson('/api/v1/admin/categories', [
                'code' => 'ops-cat',
                'name' => ['en' => 'Ops Category', 'ar' => 'فئة العمليات'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

describe('PUT /api/v1/admin/categories/{id}', function (): void {
    it('updates a category and returns 200 with new translations', function (): void {
        [, $bearer] = categoryAdminBearer();
        $category = Category::factory()->create([
            'code' => 'before-code',
            'name' => ['en' => 'Old EN', 'ar' => 'قديم'],
        ]);

        $response = $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/categories/{$category->id}", [
                'code' => 'after-code',
                'name' => ['en' => 'New EN', 'ar' => 'جديد'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.code', 'after-code')
            ->assertJsonPath('data.name.en', 'New EN');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'code' => 'after-code']);
    });

    it('allows renaming an inactive category (categories are not immutable)', function (): void {
        [, $bearer] = categoryAdminBearer();
        $category = Category::factory()->inactive()->create(['code' => 'inactive-cat']);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/categories/{$category->id}", [
                'code' => 'renamed-cat',
                'name' => ['en' => 'Renamed', 'ar' => 'مسمى'],
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'renamed-cat')
            ->assertJsonPath('data.is_active', false);
    });

    it('returns 422 when code conflicts with another category', function (): void {
        [, $bearer] = categoryAdminBearer();
        Category::factory()->create(['code' => 'taken-code']);
        $other = Category::factory()->create(['code' => 'mine']);

        $this->withHeader('Authorization', $bearer)
            ->putJson("/api/v1/admin/categories/{$other->id}", [
                'code' => 'taken-code',
                'name' => ['en' => 'Mine', 'ar' => 'مي'],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    });

    it('rejects sales role attempting to delete via DELETE (super_admin only)', function (): void {
        [, $bearer] = categoryAdminBearer(UserRole::Sales->value);
        $category = Category::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

describe('DELETE /api/v1/admin/categories/{id}', function (): void {
    it('soft-deletes a category with no products and returns 200', function (): void {
        [, $bearer] = categoryAdminBearer();
        $category = Category::factory()->create();

        $response = $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $category->id);

        expect($response->json('data.deleted_at'))->not->toBeNull();
        $this->assertSoftDeleted('categories', ['id' => $category->id]);
    });

    it('returns 422 ERR_CATEGORY_HAS_PRODUCTS when the category still contains products', function (): void {
        [, $bearer] = categoryAdminBearer();
        $category = Category::factory()->create();
        Product::factory()->forCategory($category)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_CATEGORY_HAS_PRODUCTS')
            ->assertJsonStructure(['errors' => ['products_count']]);

        // Still alive — not soft-deleted.
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    });

    it('returns 403 for sales role (super_admin only)', function (): void {
        [, $bearer] = categoryAdminBearer(UserRole::Sales->value);
        $category = Category::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 401 unauthenticated', function (): void {
        $category = Category::factory()->create();

        $this->deleteJson("/api/v1/admin/categories/{$category->id}")
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
