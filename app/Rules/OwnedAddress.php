<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Address;
use App\Models\Order;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an address id refers to a row owned by the
 * currently-authenticated user AND not soft-deleted.
 *
 * Used on `POST /api/v1/orders` for both `delivery_address_id` and
 * `billing_address_id`. Soft-deleted addresses fail (the rule does NOT
 * use `withTrashed`); customers must use a live address for a new
 * order. Historical orders pointing at trashed addresses still render
 * thanks to {@see Order::deliveryAddress()} which uses
 * `withTrashed()`.
 */
final class OwnedAddress implements ValidationRule
{
    public function __construct(
        private readonly int $userId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            // The `integer` rule has already reported a type failure; do
            // not pile on a second message.
            return;
        }

        $exists = Address::query()
            ->where('id', (int) $value)
            ->where('user_id', $this->userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $fail(__('orders.address_not_owned'));
        }
    }
}
