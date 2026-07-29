<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list shape for a {@see Testimonial} row.
 *
 * Admins see raw bilingual JSON for `quote` so the admin form can hydrate
 * both locales in one fetch (docs/architecture/admin-content.md § 4.3).
 *
 * @property-read Testimonial $resource
 */
class AdminTestimonialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Testimonial $testimonial */
        $testimonial = $this->resource;

        return [
            'id' => $testimonial->id,
            'name' => $testimonial->name,
            'role' => $testimonial->role,
            'company' => $testimonial->company,
            'avatar_url' => $testimonial->avatar_url,
            'rating' => (int) $testimonial->rating,
            'quote' => $testimonial->getTranslations('quote'),
            'display_order' => (int) $testimonial->display_order,
            'is_active' => (bool) $testimonial->is_active,
            'status' => $this->computeStatus($testimonial),
            'deleted_at' => $testimonial->deleted_at?->toIso8601String(),
            'created_at' => $testimonial->created_at->toIso8601String(),
            'updated_at' => $testimonial->updated_at->toIso8601String(),
        ];
    }

    /**
     * Synthetic status: trashed | inactive | active.
     */
    protected function computeStatus(Testimonial $testimonial): string
    {
        if ($testimonial->trashed()) {
            return 'trashed';
        }

        return $testimonial->is_active ? 'active' : 'inactive';
    }
}
