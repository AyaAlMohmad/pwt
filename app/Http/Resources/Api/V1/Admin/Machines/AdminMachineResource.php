<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Machines;

use App\Models\Address;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin list-shape of a {@see Machine}.
 *
 * Returned by GET /api/v1/admin/machines index and /due-maintenance.
 * Distinct from the customer-side `MachineResource`:
 *  - Embeds full PII customer summary (id/name/email/phone).
 *  - Exposes `filter_change_interval_months`, `source`, `order_id`,
 *    `is_trashed`.
 *
 * @property-read Machine $resource
 */
class AdminMachineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var Machine $machine */
        $machine = $this->resource;

        $nextDue = $machine->next_maintenance_due_at;
        $nextDueString = $nextDue?->toDateString();
        $needsMaintenance = $nextDue !== null && $nextDue->lessThanOrEqualTo(now()->addDays(30));
        $daysUntilDue = $nextDue !== null
            ? (int) now()->startOfDay()->diffInDays($nextDue->startOfDay(), false)
            : null;

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'display_name' => $machine->displayName(),
            'serial_number' => $machine->serial_number,
            'location_label' => $machine->location_label,
            'product' => $this->resolveProduct($machine),
            'customer' => $this->resolveCustomer($machine),
            'address' => $this->resolveAddress($machine),
            'country_code' => $machine->country_code,
            'voltage' => $machine->voltage,
            'plan' => $machine->term->value,
            'term' => $machine->term->value,
            'installed_at' => $machine->installed_at->toDateString(),
            'next_maintenance_due_at' => $nextDueString,
            'days_until_due' => $daysUntilDue,
            'filter_change_interval_months' => $machine->filter_change_interval_months,
            'is_active' => (bool) $machine->is_active,
            'individual_buy_enabled' => (bool) $machine->individual_buy_enabled,
            'source' => $machine->source->value,
            'order_id' => $machine->order_id,
            'needs_maintenance' => $needsMaintenance,
            'open_requests_count' => $this->resolveOpenRequestsCount($machine),
            'is_trashed' => $machine->trashed(),
            'created_at' => $machine->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveProduct(Machine $machine): ?array
    {
        if (! $machine->relationLoaded('product') || $machine->product === null) {
            return null;
        }

        $locale = app()->getLocale();

        return [
            'id' => $machine->product->id,
            'code' => $machine->product->code,
            'name' => $machine->product->getTranslation('name', $locale, false)
                ?: $machine->product->getTranslation('name', config('app.fallback_locale', 'en')),
            'individual_buy_enabled' => (bool) $machine->product->individual_buy_enabled,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveCustomer(Machine $machine): ?array
    {
        if (! $machine->relationLoaded('user') || $machine->user === null) {
            return null;
        }

        $user = $machine->user;
        $phoneE164 = $user->phone !== null
            ? $user->phone_country_code.$user->phone
            : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $phoneE164,
            'address' => $this->resolveCustomerAddress($user),
        ];
    }

    /**
     * The customer's default address book entry. Distinct from the
     * machine's own `address` (the installation site) — for imported
     * rows the two happen to coincide, but a customer may own machines
     * installed at several of their addresses.
     *
     * Null when `user.addresses` isn't eager-loaded or the customer has
     * no address at all.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveCustomerAddress(User $user): ?array
    {
        if (! $user->relationLoaded('addresses')) {
            return null;
        }

        $default = $user->addresses->first(static fn (Address $a): bool => (bool) $a->is_default)
            ?? $user->addresses->first();

        if ($default === null) {
            return null;
        }

        $region = $default->relationLoaded('region') ? $default->region : null;
        $country = $default->relationLoaded('country') ? $default->country : null;
        $locale = app()->getLocale();

        return [
            'id' => $default->id,
            'label' => $default->label,
            'line1' => $default->line1,
            'line2' => $default->line2,
            'area' => $default->area,
            'city' => $default->city,
            'region' => $region !== null
                ? $region->getTranslation('name', $locale, false)
                    ?: $region->getTranslation('name', (string) config('app.fallback_locale', 'en'))
                : null,
            'country' => $country !== null ? ['code' => $country->code] : null,
            'latitude' => $default->latitude !== null ? (float) $default->latitude : null,
            'longitude' => $default->longitude !== null ? (float) $default->longitude : null,
            'is_default' => (bool) $default->is_default,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveAddress(Machine $machine): ?array
    {
        if (! $machine->relationLoaded('address') || $machine->address === null) {
            return null;
        }
        $address = $machine->address;
        $country = $address->relationLoaded('country') ? $address->country : null;

        return [
            'id' => $address->id,
            'label' => $address->label,
            'city' => $address->city,
            'country' => $country !== null
                ? ['code' => $country->code]
                : null,
        ];
    }

    protected function resolveOpenRequestsCount(Machine $machine): int
    {
        $value = $machine->getAttribute('open_requests_count');

        return is_numeric($value) ? (int) $value : 0;
    }
}
