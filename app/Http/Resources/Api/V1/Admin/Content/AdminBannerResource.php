<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list shape for a {@see Banner} row.
 *
 * Admins see raw bilingual JSON for translatable fields so the admin form
 * can hydrate both locales in one fetch (docs/architecture/admin-content.md
 * § 4.1).
 *
 * @property-read Banner $resource
 */
class AdminBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Banner $banner */
        $banner = $this->resource;

        return [
            'id' => $banner->id,
            'code' => $banner->code,
            'placement' => $banner->placement->value,
            'title' => $banner->getTranslations('title'),
            'subtitle' => $banner->getTranslations('subtitle'),
            'image_url' => $banner->image_url,
            'image_url_ar' => $banner->image_url_ar,
            'cta_label' => $banner->getTranslations('cta_label'),
            'cta_url' => $banner->cta_url,
            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),
            'display_order' => (int) $banner->display_order,
            'is_active' => (bool) $banner->is_active,
            'status' => $this->computeStatus($banner),
            'deleted_at' => $banner->deleted_at?->toIso8601String(),
            'created_at' => $banner->created_at->toIso8601String(),
            'updated_at' => $banner->updated_at->toIso8601String(),
        ];
    }

    /**
     * Synthetic status: trashed | inactive | scheduled | expired | active.
     */
    protected function computeStatus(Banner $banner): string
    {
        if ($banner->trashed()) {
            return 'trashed';
        }
        if (! $banner->is_active) {
            return 'inactive';
        }
        $now = now();
        if ($banner->starts_at !== null && $banner->starts_at->isAfter($now)) {
            return 'scheduled';
        }
        if ($banner->ends_at !== null && $banner->ends_at->isBefore($now)) {
            return 'expired';
        }

        return 'active';
    }
}
