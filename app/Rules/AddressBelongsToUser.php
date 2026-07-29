<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Address;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an `address_id` refers to a row owned by a SPECIFIC
 * user (not necessarily the authenticated actor — used by admin
 * machine endpoints where the address must belong to the machine's
 * owning customer, not the calling admin).
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.10.
 */
final class AddressBelongsToUser implements ValidationRule
{
    public function __construct(
        private readonly int $userId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $exists = Address::query()
            ->where('id', (int) $value)
            ->where('user_id', $this->userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $fail(__('admin.machines.address_not_owned'));
        }
    }
}
