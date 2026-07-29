<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| Admin Products — Status toggle feature tests
|--------------------------------------------------------------------------
|
| Covers PATCH /api/v1/admin/products/{id}/status.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function productStatusAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('PATCH /api/v1/admin/products/{id}/status', function (): void {
    it('sets is_featured=true and writes a product.status_changed activity row', function (): void {
        [$admin, $bearer] = productStatusAdminBearer();
        $product = Product::factory()->create(['is_featured' => false]);

        $response = $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'is_featured' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.is_featured', true);

        $product->refresh();
        expect($product->is_featured)->toBeTrue();

        $entry = Activity::query()
            ->where('event', 'product.status_changed')
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($admin->id)
            ->and($entry->properties['before']['is_featured'])->toBeFalse()
            ->and($entry->properties['after']['is_featured'])->toBeTrue()
            ->and($entry->properties['changed_fields'])->toContain('is_featured');
    });

    it('sets stock_status=coming_soon and returns 200 with the new value', function (): void {
        [, $bearer] = productStatusAdminBearer();
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'stock_status' => 'coming_soon',
            ])
            ->assertOk()
            ->assertJsonPath('data.stock_status', 'coming_soon');

        $product->refresh();
        expect($product->stock_status->value)->toBe('coming_soon');
    });

    it('allows sales role to update status (200)', function (): void {
        [, $bearer] = productStatusAdminBearer(UserRole::Sales->value);
        $product = Product::factory()->create(['is_active' => true]);

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('rejects operations role with 403 (no products.update)', function (): void {
        [, $bearer] = productStatusAdminBearer(UserRole::Operations->value);
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'is_active' => false,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });

    it('returns 422 when the body is empty (at least one flag required)', function (): void {
        [, $bearer] = productStatusAdminBearer();
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_VALIDATION');
    });

    it('returns 422 when stock_status is invalid', function (): void {
        [, $bearer] = productStatusAdminBearer();
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->patchJson("/api/v1/admin/products/{$product->id}/status", [
                'stock_status' => 'invalid_value',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['stock_status']]);
    });

    it('returns 404 for an unknown product id', function (): void {
        [, $bearer] = productStatusAdminBearer();

        $this->withHeader('Authorization', $bearer)
            ->patchJson('/api/v1/admin/products/999999/status', ['is_active' => true])
            ->assertStatus(404);
    });

    it('returns 401 when unauthenticated', function (): void {
        $product = Product::factory()->create();

        $this->patchJson("/api/v1/admin/products/{$product->id}/status", ['is_active' => false])
            ->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
