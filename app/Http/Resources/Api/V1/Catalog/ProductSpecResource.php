<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\ProductSpec;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProductSpec $resource
 */
final class ProductSpecResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductSpec $spec */
        $spec = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $spec->id,
            'label' => $spec->getTranslation('label', $locale),
            'value' => $spec->getTranslation('value', $locale),
            'display_order' => (int) $spec->display_order,
        ];
    }
}
