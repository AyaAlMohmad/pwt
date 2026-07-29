<?php

declare(strict_types=1);

namespace App\Services\Addresses;

use App\Enums\OrderStatus;
use App\Exceptions\Addresses\AddressLimitReachedException;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates lifecycle operations on {@see Address} rows.
 *
 * All default-mutation paths run inside `DB::transaction(...)` with
 * `lockForUpdate()` on the caller's address rows so the
 * "at most one is_default=true per user" invariant survives concurrent
 * requests. MySQL 8 cannot enforce this through a partial unique index,
 * hence the pessimistic-lock approach documented in
 * docs/architecture/addresses.md § 9.4.
 *
 * The Phase 8 cross-module guard (ERR_ADDRESS_IN_USE) is stubbed via
 * {@see isAddressInUse()} — see § 9.6 of the architecture doc.
 */
final class AddressService
{
    /**
     * Returns the caller's full address book, sorted with the default
     * first then by most recently created. Eager-loads country/region
     * to keep the resource render N+1-free.
     *
     * @return Collection<int, Address>
     */
    public function listForUser(User $user): Collection
    {
        return Address::query()
            ->with(['country', 'region'])
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Create a new address owned by $user.
     *
     * Atomicity:
     *  1. SELECT ... FOR UPDATE on the user's existing rows (cap check
     *     + previous-default lookup share the same lock).
     *  2. If the cap is reached → throw {@see AddressLimitReachedException}.
     *  3. If this is the user's first address → force is_default=true.
     *  4. If is_default=true in $data → demote any previous default.
     *  5. Insert the new row.
     *
     * @param  array<string, mixed>  $data  Validated payload (StoreAddressRequest::validated()).
     */
    public function create(User $user, array $data): Address
    {
        $max = (int) config('addresses.max_per_user', 10);

        return DB::transaction(function () use ($user, $data, $max): Address {
            /** @var Collection<int, Address> $existing */
            $existing = Address::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            if ($existing->count() >= $max) {
                throw AddressLimitReachedException::create($max);
            }

            $isDefault = (bool) ($data['is_default'] ?? false);

            // First-address-promotion rule: a brand new address book
            // gets its first entry marked as default automatically so
            // there is always a sensible checkout pick.
            if ($existing->isEmpty()) {
                $isDefault = true;
            }

            if ($isDefault) {
                $this->demoteDefaults($user->id, null);
            }

            $payload = $data;
            $payload['user_id'] = $user->id;
            $payload['is_default'] = $isDefault;
            // user_id is server-set; drop any client attempt to spoof.
            unset($payload['id']);

            /** @var Address $address */
            $address = Address::query()->create($payload);

            return $address->load(['country', 'region']);
        });
    }

    /**
     * Apply a partial update to $address.
     *
     * When the payload flips `is_default` to true, the previous default
     * (if any other than $address) is demoted inside the same
     * transaction. When the payload flips it to false, no automatic
     * re-promotion happens — the customer is permitted to have zero
     * defaults.
     *
     * @param  array<string, mixed>  $data  Validated payload.
     */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data): Address {
            // Lock the user's rows so the demote-others step is race-safe.
            Address::query()
                ->where('user_id', $address->user_id)
                ->lockForUpdate()
                ->get();

            $promoting = array_key_exists('is_default', $data) && $data['is_default'] === true;

            if ($promoting && ! $address->is_default) {
                $this->demoteDefaults($address->user_id, $address->id);
            }

            // Strip server-managed keys before fill.
            unset($data['user_id'], $data['id']);

            $address->fill($data);
            $address->save();

            return $address->refresh()->load(['country', 'region']);
        });
    }

    /**
     * Soft-delete the address. If the deleted row was the default,
     * auto-promote the next-most-recent surviving address. Returns the
     * id of the newly-promoted address (or null if none was promoted).
     *
     * The Phase 8 ERR_ADDRESS_IN_USE guard is checked upstream by the
     * controller via {@see isAddressInUse()} — keeping it out of the
     * service keeps this method's contract simple (delete always
     * succeeds when reached here).
     */
    public function delete(Address $address): ?int
    {
        return DB::transaction(function () use ($address): ?int {
            $wasDefault = $address->is_default;
            $userId = $address->user_id;

            // Lock the user's row-set so the promotion step is race-safe.
            Address::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->get();

            $address->delete();

            if (! $wasDefault) {
                return null;
            }

            /** @var Address|null $candidate */
            $candidate = Address::query()
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->first();

            if ($candidate === null) {
                return null;
            }

            $candidate->forceFill(['is_default' => true])->save();

            return $candidate->id;
        });
    }

    /**
     * Returns true if the address is currently referenced (as either
     * delivery or billing) by an order whose status is non-terminal —
     * `pending`, `confirmed`, `scheduled`, or `out_for_delivery`.
     *
     * Completed and cancelled orders do NOT block deletion (they are
     * historical and the soft-deleted address is still queryable via
     * `Address::withTrashed()`).
     *
     * Wired in Phase 8 per docs/architecture/addresses.md § 9.6 and
     * docs/architecture/orders.md § 9.8.
     */
    public function isAddressInUse(Address $address): bool
    {
        $active = [
            OrderStatus::Pending->value,
            OrderStatus::Confirmed->value,
            OrderStatus::Scheduled->value,
            OrderStatus::OutForDelivery->value,
        ];

        return Order::query()
            ->where(static function ($q) use ($address): void {
                $q->where('delivery_address_id', $address->id)
                    ->orWhere('billing_address_id', $address->id);
            })
            ->whereIn('status', $active)
            ->exists();
    }

    /**
     * Set is_default=false on every non-trashed row owned by $userId
     * except $exceptId (when non-null).
     */
    private function demoteDefaults(int $userId, ?int $exceptId): void
    {
        $query = Address::query()
            ->where('user_id', $userId)
            ->where('is_default', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $query->update(['is_default' => false]);
    }
}
