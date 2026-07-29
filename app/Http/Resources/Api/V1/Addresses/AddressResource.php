<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Addresses;

use App\Http\Resources\Api\V1\Reference\CountryResource;
use App\Http\Resources\Api\V1\Reference\RegionResource;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of an {@see Address} row.
 *
 * Allowlist (per docs/architecture/addresses.md § 4.1):
 *   id, label, recipient_name, recipient_phone,
 *   recipient_phone_country_code, line1, line2, city, area,
 *   postal_code, notes, country, region, is_default, latitude,
 *   longitude, created_at, updated_at.
 *
 * Never serialised: user_id, country_id, region_id, deleted_at.
 *
 * Country/Region nested resources are only emitted when the relation is
 * eager-loaded by the caller (controllers MUST `with(['country','region'])`
 * before returning a resource).
 *
 * @property-read Address $resource
 */
final class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Address $address */
        $address = $this->resource;

        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'recipient_phone_country_code' => $address->recipient_phone_country_code,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city ?? ($address->region?->getTranslation('name', app()->getLocale())),
            'area' => $address->area,
            'postal_code' => $address->postal_code,
            'notes' => $address->notes,
            'country' => new CountryResource($this->whenLoaded('country')),
            'city_id' => $address->region_id,
            'city_detail' => $address->relationLoaded('region') && $address->region !== null
                ? new RegionResource($address->region)
                : null,
            'is_default' => (bool) $address->is_default,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'created_at' => $address->created_at?->toIso8601String(),
            'updated_at' => $address->updated_at?->toIso8601String(),
        ];
    }
}
