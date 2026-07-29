<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Reference;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * City resource — maps the underlying {@see Region} model.
 *
 * In the GCC context the regions table stores emirates / governorates
 * which the consumer UI treats as cities.
 *
 * @property-read Region $resource
 */
final class CityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Region $region */
        $region = $this->resource;

        return [
            'id' => $region->id,
            'code' => $region->code,
            'name' => $region->getTranslation('name', app()->getLocale()),
            'is_active' => (bool) $region->is_active,
        ];
    }
}
