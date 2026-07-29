<?php

declare(strict_types=1);

use App\Enums\FileType;
use App\Enums\UserRole;
use App\Models\File;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| Admin Products — Images attach / detach feature tests
|--------------------------------------------------------------------------
|
| Covers POST + DELETE /api/v1/admin/products/{id}/images[/{imageId}].
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    resetAuthTestState();
});

/**
 * @return array{0: User, 1: string}
 */
function productImageAdminBearer(string $role = UserRole::SuperAdmin->value): array
{
    $admin = User::factory()->admin($role)->create();
    $token = $admin->createToken('admin', ['admin'])->plainTextToken;

    return [$admin, 'Bearer '.$token];
}

describe('POST /api/v1/admin/products/{id}/images', function (): void {
    it('attaches a product_image File row and returns full product detail', function (): void {
        [$admin, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();
        $file = File::factory()->forType(FileType::ProductImage)->create(['user_id' => $admin->id]);

        $response = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $file->id,
                'alt_text' => ['en' => 'Front view', 'ar' => 'منظر أمامي'],
                'is_primary' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id);

        $images = $response->json('data.images');
        expect($images)->toHaveCount(1);
        expect($images[0]['is_primary'])->toBeTrue();
        expect($images[0]['file_id'])->toBe($file->id);
        expect($images[0]['alt_text']['en'])->toBe('Front view');

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'file_id' => $file->id,
            'is_primary' => true,
        ]);
    });

    it('demotes the previous primary when is_primary=true', function (): void {
        [$admin, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();
        $existingFile = File::factory()->forType(FileType::ProductImage)->create(['user_id' => $admin->id]);
        $existingPrimary = ProductImage::factory()->forProduct($product)->primary()->create([
            'file_id' => $existingFile->id,
        ]);
        expect($existingPrimary->fresh()->is_primary)->toBeTrue();

        $file = File::factory()->forType(FileType::ProductImage)->create(['user_id' => $admin->id]);

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $file->id,
                'is_primary' => true,
            ])
            ->assertOk();

        // Old primary demoted to false in the same transaction.
        expect($existingPrimary->fresh()->is_primary)->toBeFalse();
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'file_id' => $file->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseCount('product_images', 2);
    });

    it('returns 422 when the file is the wrong type (Generic)', function (): void {
        [$admin, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();
        $wrongTypeFile = File::factory()->create(['user_id' => $admin->id]); // default FileType::Generic

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $wrongTypeFile->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file_id']]);
    });

    it('accepts a product_image File uploaded by another admin (Option B — shared library)', function (): void {
        // docs/architecture/admin-catalog.md § 9.3 resolves this open question
        // as Option B: no ownership check on file_id.
        [, $bearerA] = productImageAdminBearer();
        $otherAdmin = User::factory()->admin(UserRole::Sales->value)->create();
        $product = Product::factory()->create();
        $file = File::factory()->forType(FileType::ProductImage)->create(['user_id' => $otherAdmin->id]);

        $this->withHeader('Authorization', $bearerA)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $file->id,
            ])
            ->assertOk();
    });

    it('is idempotent on the same file_id — attaching twice keeps a single row', function (): void {
        [$admin, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();
        $file = File::factory()->forType(FileType::ProductImage)->create(['user_id' => $admin->id]);

        $first = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $file->id,
                'alt_text' => ['en' => 'First'],
            ])
            ->assertOk();
        $firstImageId = $first->json('data.images.0.id');

        $second = $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [
                'file_id' => $file->id,
                'alt_text' => ['en' => 'Second'],
            ])
            ->assertOk();

        expect($second->json('data.images'))->toHaveCount(1);
        expect($second->json('data.images.0.id'))->toBe($firstImageId);
        $this->assertDatabaseCount('product_images', 1);
    });

    it('returns 422 when file_id is missing', function (): void {
        [, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->postJson("/api/v1/admin/products/{$product->id}/images", [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file_id']]);
    });
});

describe('DELETE /api/v1/admin/products/{productId}/images/{imageId}', function (): void {
    it('removes an image row and returns 200 with the refreshed product', function (): void {
        [, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();
        $image = ProductImage::factory()->forProduct($product)->create();
        expect(ProductImage::where('id', $image->id)->exists())->toBeTrue();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id);

        expect(ProductImage::where('id', $image->id)->exists())->toBeFalse();
    });

    it('returns 404 ERR_NOT_FOUND when the image belongs to another product', function (): void {
        [, $bearer] = productImageAdminBearer();
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();
        $imageOfB = ProductImage::factory()->forProduct($productB)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$productA->id}/images/{$imageOfB->id}")
            ->assertStatus(404);

        // The image must NOT have been deleted.
        $this->assertDatabaseHas('product_images', ['id' => $imageOfB->id]);
    });

    it('returns 404 when the image id does not exist', function (): void {
        [, $bearer] = productImageAdminBearer();
        $product = Product::factory()->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}/images/999999")
            ->assertStatus(404);
    });

    it('rejects support role with 403', function (): void {
        [, $bearer] = productImageAdminBearer(UserRole::Support->value);
        $product = Product::factory()->create();
        $image = ProductImage::factory()->forProduct($product)->create();

        $this->withHeader('Authorization', $bearer)
            ->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});
