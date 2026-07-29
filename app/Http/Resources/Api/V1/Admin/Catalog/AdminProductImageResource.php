<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Http\Resources\Api\V1\Catalog\ProductImageResource;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side image row shape.
 *
 * Differs from the customer-facing
 * {@see ProductImageResource}:
 *  - Returns raw bilingual JSON for `alt_text`.
 *  - Exposes `file_id`, `created_at`, `updated_at`.
 *
 * @property-read ProductImage $resource
 */
final class AdminProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductImage $image */
        $image = $this->resource;

        return [
            'id' => $image->id,
            'file_id' => $image->file_id !== null ? (int) $image->file_id : null,
            'url' => $this->resolveUrl($image->url, $request),
            'alt_text' => $image->getTranslations('alt_text'),
            'is_primary' => (bool) $image->is_primary,
            'display_order' => (int) $image->display_order,
            'created_at' => $image->created_at->toIso8601String(),
            'updated_at' => $image->updated_at->toIso8601String(),
        ];
    }

    private function resolveUrl(?string $path, Request $request): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $root = rtrim($request->getSchemeAndHttpHost(), '/');

        return $root . '/storage/' . ltrim($path, '/');
    }
}
