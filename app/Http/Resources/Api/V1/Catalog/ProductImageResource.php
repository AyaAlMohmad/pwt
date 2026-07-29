<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a {@see ProductImage} row.
 *
 * @property-read ProductImage $resource
 */
final class ProductImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductImage $image */
        $image = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $image->id,
            'url' => $this->resolveUrl($image->url, $request),
            'alt_text' => $image->getTranslation('alt_text', $locale, false) ?: null,
            'is_primary' => (bool) $image->is_primary,
            'display_order' => (int) $image->display_order,
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
