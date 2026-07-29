<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Promotions;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-facing shape of a {@see Testimonial} row.
 *
 * `quote` is the only translatable field. `role` / `company` are plain
 * strings in Phase 7 (docs/architecture/promotions.md § 4.4 + § 9.7).
 * `is_active` is intentionally hidden — visibility is enforced by the
 * scope and there is no useful customer signal in re-asserting it.
 *
 * @property-read Testimonial $resource
 */
final class TestimonialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Testimonial $testimonial */
        $testimonial = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $testimonial->id,
            'name' => $testimonial->name,
            'role' => $testimonial->role,
            'company' => $testimonial->company,
            'avatar_url' => $this->resolveAvatarUrl($testimonial->avatar_url),
            'rating' => (int) $testimonial->rating,
            'quote' => $testimonial->getTranslation('quote', $locale),
            'display_order' => (int) $testimonial->display_order,
        ];
    }

    private function resolveAvatarUrl(?string $path): ?string
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
