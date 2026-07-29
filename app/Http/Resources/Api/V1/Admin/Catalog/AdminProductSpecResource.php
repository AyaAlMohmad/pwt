<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Models\ProductSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side spec row shape — raw bilingual JSON for both `label` and
 * `value` so the admin form can edit each locale in one fetch.
 *
 * @property-read ProductSpec $resource
 */
final class AdminProductSpecResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductSpec $spec */
        $spec = $this->resource;

        return [
            'id' => $spec->id,
            'label' => $spec->getTranslations('label'),
            'value' => $spec->getTranslations('value'),
            'display_order' => (int) $spec->display_order,
            'created_at' => $spec->created_at->toIso8601String(),
            'updated_at' => $spec->updated_at->toIso8601String(),
        ];
    }
}
