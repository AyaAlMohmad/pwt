<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Maintenance;

use App\Http\Resources\Api\V1\Reference\CountryResource;
use App\Models\Machine;
use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-shape of a {@see Machine} row — used by `GET /me/machines`.
 *
 * Hides operational metadata (source, order_id, filter interval,
 * notes); those are detail-only. See
 * docs/architecture/machines-maintenance.md § 4.1.
 *
 * @property-read Machine $resource
 */
class MachineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Machine $machine */
        $machine = $this->resource;

        $order = $machine->order;
        $orderItem = $order !== null && $order->relationLoaded('items')
            ? $order->items->firstWhere('product_id', $machine->product_id)
            : null;

        return [
            'id' => $machine->id,
            'public_id' => $machine->public_id,
            'display_name' => $machine->displayName(),
            'serial_number' => $machine->serial_number,
            'product' => $this->resolveProduct($machine),
            'product_price' => $this->resolveProductPrice($machine),
            'location_label' => $machine->location_label,
            'country_code' => $machine->country_code,
            'installed_at' => $machine->installed_at->toDateString(),
            'next_maintenance_due_at' => $machine->next_maintenance_due_at?->toDateString(),
            'is_active' => (bool) $machine->is_active,
            'term' => $machine->term->value,
            'status' => $this->resolveStatus($machine),
            'address' => $this->resolveAddressSmall($machine),
            'needs_maintenance' => $this->computeNeedsMaintenance($machine),
            'can_request_maintenance' => (bool) $machine->is_active,
            'order' => $order !== null ? [
                'id' => $order->id,
                'public_id' => $order->public_id,
                'term' => $order->term->value,
                'total_amount' => $order->total_amount,
                'currency' => $order->currency,
                'installed_at' => $order->installed_at?->toDateString(),
                'completed_at' => $order->completed_at?->toDateString(),
            ] : null,
            'rental_price' => $orderItem !== null ? $orderItem->unit_price : null,
            'rental_total' => $orderItem !== null ? $orderItem->line_total : null,
            'order_item' => $orderItem !== null ? [
                'quantity' => $orderItem->quantity,
                'unit_price' => $orderItem->unit_price,
                'line_total' => $orderItem->line_total,
                'rent_period' => $orderItem->rent_period,
                'rent_min_term_months' => $orderItem->rent_min_term_months,
            ] : null,
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

        $product = $machine->product;
        $locale = app()->getLocale();

        $images = [];
        if ($product->relationLoaded('images')) {
            foreach ($product->images as $image) {
                $images[] = [
                    'id' => $image->id,
                    'url' => $this->resolveImageUrl($image->url),
                    'alt_text' => $image->getTranslation('alt_text', $locale, false) ?: null,
                    'is_primary' => (bool) $image->is_primary,
                    'display_order' => (int) $image->display_order,
                ];
            }
        }

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->getTranslation('name', $locale),
            'short_description' => $product->getTranslation('short_description', $locale),
            'individual_buy_enabled' => (bool) $product->individual_buy_enabled,
            'images' => $images,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveProductPrice(Machine $machine): ?array
    {
        $product = $machine->product;
        if ($product === null || ! $product->relationLoaded('activePrices')) {
            return null;
        }

        /** @var ProductPrice|null $row */
        $row = $product->activePrices
            ->where('term', $machine->term->value)
            ->sortBy(fn (ProductPrice $p): float => (float) $p->amount)
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'term' => $row->term->value,
            'amount' => (string) $row->amount,
            'currency' => $row->currency,
            'period' => $row->period,
            'min_term_months' => $row->min_term_months !== null ? (int) $row->min_term_months : null,
        ];
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $root = rtrim(request()->getSchemeAndHttpHost(), '/');

        return $root . '/storage/' . ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveAddressSmall(Machine $machine): ?array
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
                ? (new CountryResource($country))->resolve()
                : null,
        ];
    }

    /**
     * Resolve a customer-facing status label.
     */
    protected function resolveStatus(Machine $machine): string
    {
        return match ($machine->term->value) {
            'rent' => 'rented',
            'buy' => 'purchased',
            default => 'purchased',
        };
    }

    /**
     * Compute the "due soon OR overdue" boolean. The 30-day window matches
     * `MaintenanceScheduler::dueWithinDays(30)` so the resource and the
     * `needs_maintenance` filter agree.
     */
    protected function computeNeedsMaintenance(Machine $machine): bool
    {
        if (! (bool) $machine->is_active) {
            return false;
        }

        $due = $machine->next_maintenance_due_at;
        if ($due === null) {
            return false;
        }

        return $due->lessThanOrEqualTo(now()->addDays(30)->endOfDay());
    }
}
