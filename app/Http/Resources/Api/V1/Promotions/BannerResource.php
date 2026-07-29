<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Promotions;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-facing shape of a {@see Banner} row.
 *
 * Allowlist per docs/architecture/promotions.md § 4.1:
 *   id, code, placement, title, subtitle, image_url (locale-aware),
 *   cta_label, cta_url, starts_at, ends_at, display_order, is_active.
 *
 * Never serialised: `image_url_ar` raw, `created_at`, `updated_at`,
 * `deleted_at`, audit columns (Phase 20).
 *
 * @property-read Banner $resource
 */
final class BannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Banner $banner */
        $banner = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $banner->id,
            'code' => $banner->code,
            'placement' => $banner->placement->value,
            'title' => $banner->getTranslation('title', $locale, false) ?: null,
            'subtitle' => $banner->getTranslation('subtitle', $locale, false) ?: null,
            'image_url' => $this->resolveImageUrl($banner, $locale),
            'cta_label' => $banner->getTranslation('cta_label', $locale, false) ?: null,
            'cta_url' => $banner->cta_url,
            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),
            'display_order' => (int) $banner->display_order,
            'is_active' => (bool) $banner->is_active,
        ];
    }

    /**
     * Locale-aware URL resolution.
     *
     * When the active locale is Arabic and the banner has an `image_url_ar`
     * variant, that URL is returned. Otherwise the default `image_url`
     * is used. In both cases relative paths are resolved through the
     * `public` disk to absolute URLs; fully-qualified URLs pass through.
     */
    private function resolveImageUrl(Banner $banner, string $locale): string
    {
        $path = ($locale === 'ar' && $banner->image_url_ar !== null && $banner->image_url_ar !== '')
            ? $banner->image_url_ar
            : $banner->image_url;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
