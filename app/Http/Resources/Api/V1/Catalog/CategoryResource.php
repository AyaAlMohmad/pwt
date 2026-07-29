<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public shape of a {@see Category} row.
 *
 * Allowlist (docs/architecture/catalog.md § 4.1):
 *   id, code, name, description, image_url, products_count,
 *   is_active, display_order, created_at.
 *
 * Never serialised:
 *   parent_id, updated_at, deleted_at.
 *
 * `image_url` is converted from the stored relative path to an absolute
 * URL via the `public` disk so the customer can render it directly.
 * `products_count` is rendered only when the caller eager-loaded it
 * with `withCount('products')` (detail endpoint).
 *
 * @property-read Category $resource
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->getTranslation('name', $locale),
            'description' => $category->getTranslation('description', $locale, false) ?: null,
            'image_url' => $this->resolveImageUrl($category->image_url),
            'products_count' => $this->whenCounted('products'),
            'is_active' => (bool) $category->is_active,
            'display_order' => (int) $category->display_order,
            'created_at' => $category->created_at->toIso8601String(),
        ];
    }

    /**
     * Resolve a stored path to an absolute URL via the `public` disk.
     * Returns null when the path is null/empty or when it is already a
     * fully-qualified URL.
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
