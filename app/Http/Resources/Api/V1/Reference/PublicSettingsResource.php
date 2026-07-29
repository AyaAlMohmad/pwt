<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms the assembled public-settings array into the locale-
 * resolved envelope payload.
 *
 * The controller / service is responsible for building the underlying
 * associative array (already filtered by the public allowlist); this
 * resource just collapses the bilingual `support_hours` entry to the
 * active locale and casts a couple of numeric maps.
 *
 * @property-read array<string, mixed> $resource
 */
final class PublicSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;
        $locale = app()->getLocale();

        $supportHours = $data['support_hours'] ?? null;
        if (is_array($supportHours)) {
            $supportHours = $supportHours[$locale] ?? $supportHours['en'] ?? null;
        }

        return [
            'supported_locales' => $data['supported_locales'] ?? ['en', 'ar'],
            'default_locale' => $data['default_locale'] ?? 'en',
            'currencies_by_country' => $data['currencies_by_country'] ?? [],
            'vat_rates_by_country' => $data['vat_rates_by_country'] ?? [],
            'phone_number' => $data['phone_number'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'location' => $data['location'] ?? null,
            'social_links' => $data['social_links'] ?? [],
            'support_hours' => $supportHours,
        ];
    }
}
