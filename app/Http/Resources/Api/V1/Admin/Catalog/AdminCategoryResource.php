<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Api\V1\Catalog\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Admin shape for a {@see Category} row.
 *
 * Differs from the customer {@see CategoryResource}:
 *  - Returns raw bilingual JSON for `name` and `description` so the
 *    admin form can hydrate both locales in one fetch.
 *  - Exposes `parent_id`, `updated_at`, `deleted_at`, and counts.
 *  - `products_count` reflects ALL products (active + inactive); the
 *    `active_products_count` is rendered only when explicitly counted
 *    via `loadCount('products as active_products_count', ...)`.
 *
 * Used for index list rows AND detail responses (single resource).
 *
 * @property-read Category $resource
 */
class AdminCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->getTranslations('name'),
            'description' => $category->getTranslations('description'),
            'image_url' => $this->resolveImageUrl($category->image_url),
            'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
            'display_order' => (int) $category->display_order,
            'is_active' => (bool) $category->is_active,
            'deleted_at' => $category->deleted_at?->toIso8601String(),
            'created_at' => $category->created_at->toIso8601String(),
            'updated_at' => $category->updated_at->toIso8601String(),
            'products_count' => $this->whenCounted('products'),
            'active_products_count' => $this->when(
                isset($category->active_products_count),
                /** @phpstan-ignore-next-line property.notFound */
                fn (): int => (int) $category->active_products_count,
            ),
            'parent' => $this->whenLoaded('parent', function () use ($category): ?array {
                $parent = $category->parent;
                if ($parent === null) {
                    return null;
                }

                return [
                    'id' => $parent->id,
                    'code' => $parent->code,
                    'name' => $parent->getTranslations('name'),
                ];
            }),
        ];
    }

    /**
     * Resolve a stored path to an absolute URL via the `public` disk.
     */
    private function resolveImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
