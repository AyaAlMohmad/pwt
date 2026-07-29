<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Enums\MachineSource;
use App\Models\Address;
use App\Models\Machine;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Phase 18 will inject MaintenanceScheduler here to set
// next_maintenance_due_at the moment an admin records the filter
// interval. Customer Phase 10 leaves the field null.

/**
 * Creates one {@see Machine} row per `(item × quantity)` unit when an
 * order reaches the `installed` status.
 *
 * Idempotent on `order_id` — second event delivery short-circuits and
 * returns the existing rows.
 *
 * See docs/architecture/machines-maintenance.md § 9.7.
 */
final class MachineCreationFromOrderService
{
    /**
     * @return list<Machine>
     */
    public function createFromOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            // Idempotency: if any machines already exist for this order,
            // return them as-is.
            $existing = Machine::query()
                ->where('order_id', $order->id)
                ->get()
                ->all();
            if ($existing !== []) {
                return $existing;
            }

            $order->loadMissing([
                'items.product',
                'deliveryAddress.country',
                'installation',
            ]);

            $deliveryAddress = $order->deliveryAddress;
            $country = $deliveryAddress?->country;
            $countryCode = strtolower(
                (string) ($country !== null ? $country->code : 'ae'),
            );
            $locationLabel = $deliveryAddress?->label;
            $installedAt = $order->installed_at instanceof Carbon
                ? $order->installed_at->copy()->startOfDay()
                : Carbon::today();
            $orderInstallationId = $order->installation?->id;

            /** @var list<Machine> $created */
            $created = [];

            foreach ($order->items as $lineIndex => $item) {
                /** @var OrderItem $item */
                $product = $item->relationLoaded('product') ? $item->product : null;

                // Skip non-machine-eligible items (e.g. accessories).
                if ($product === null || ! (bool) $product->is_machine_eligible) {
                    continue;
                }

                $quantity = max(1, (int) $item->quantity);
                for ($qtyIndex = 0; $qtyIndex < $quantity; $qtyIndex++) {
                    $serial = $this->buildSerial(
                        $order->public_id,
                        (int) $lineIndex,
                        $qtyIndex,
                    );

                    $machine = Machine::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $order->user_id,
                        'address_id' => $this->resolveAddressId($order, $deliveryAddress),
                        'product_id' => $item->product_id,
                        'serial_number' => $serial,
                        'term' => $item->term->value,
                        'installed_at' => $installedAt->toDateString(),
                        'filter_change_interval_months' => null,
                        'next_maintenance_due_at' => null,
                        'location_label' => $locationLabel,
                        'country_code' => $countryCode,
                        'is_active' => true,
                        'source' => MachineSource::Created->value,
                        'order_id' => $order->id,
                        'order_installation_id' => $orderInstallationId,
                    ]);

                    $created[] = $machine;
                }
            }

            return $created;
        });
    }

    /**
     * Build the `PENDING-…` sentinel serial. UUIDs are 36 chars; even
     * with the prefix + indices the result stays under the 80-char
     * column cap.
     */
    private function buildSerial(string $orderPublicId, int $lineIndex, int $qtyIndex): string
    {
        $serial = sprintf(
            'PENDING-%s-%02d-%02d',
            $orderPublicId,
            $lineIndex,
            $qtyIndex,
        );

        return Str::limit($serial, 80, '');
    }

    /**
     * Defensive address resolver — falls back to the order's billing
     * address, or creates a synthetic address from manual order fields
     * when no saved address was selected at checkout.
     */
    private function resolveAddressId(Order $order, ?Address $deliveryAddress): int
    {
        if ($deliveryAddress !== null) {
            return $deliveryAddress->id;
        }

        if ($order->delivery_address_id !== null) {
            return (int) $order->delivery_address_id;
        }

        if ($order->billing_address_id !== null) {
            return (int) $order->billing_address_id;
        }

        // No saved address — synthesise one from the manual fields stored
        // on the order row (delivery_address_line_1 / delivery_city).
        return $this->createSyntheticAddress($order)->id;
    }

    /**
     * Create an address book entry from the free-text fields the
     * customer typed at checkout (used when they didn't pick a saved
     * address).  The label is auto-generated so the row is
     * recognisable in the admin UI.
     */
    private function createSyntheticAddress(Order $order): Address
    {
        $line1 = (string) ($order->delivery_address_line_1 ?? '');
        $city  = (string) ($order->delivery_city ?? '');
        $post  = (string) ($order->delivery_postcode ?? '');

        $label = $line1 !== '' && $city !== ''
            ? "{$line1}, {$city}"
            : ($line1 !== '' ? $line1 : __('orders.synthetic_address_label'));

        /** @var Address $address */
        $address = Address::query()->create([
            'user_id' => $order->user_id,
            'country_id' => $this->resolveCountryId($order->country_code ?? 'ae'),
            'label' => $label,
            'recipient_name' => $order->customer_name ?? '',
            'recipient_phone' => $order->customer_phone ?? '',
            'recipient_phone_country_code' => '',
            'line1' => $line1,
            'city' => $city,
            'postal_code' => $post !== '' ? $post : null,
            'is_default' => false,
        ]);

        // Link the synthetic address back to the order so repeated
        // calls (idempotency replays) reuse the same row.
        $order->delivery_address_id = $address->id;
        $order->save();

        return $address;
    }

    private function resolveCountryId(string $code): int
    {
        /** @var \App\Models\Country|null $country */
        $country = \App\Models\Country::query()
            ->where('code', strtolower($code))
            ->first();

        return $country?->id ?? 1;
    }
}
