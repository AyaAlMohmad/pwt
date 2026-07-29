<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Reference;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Country $resource
 */
final class CountryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Country $country */
        $country = $this->resource;

        return [
            'id' => $country->id,
            'code' => $country->code,
            'name' => $country->getTranslation('name', app()->getLocale()),
            'phone_code' => $country->phone_code,
            'currency' => $country->currency,
            'vat_rate' => (float) $country->vat_rate,
            'is_active' => (bool) $country->is_active,
            'regions' => RegionResource::collection($this->whenLoaded('regions')),
        ];
    }
}
