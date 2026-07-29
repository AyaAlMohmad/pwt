<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Machine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a `machine_id` refers to an ACTIVE, NON-TRASHED
 * machine owned by a specific customer. Used by the admin maintenance
 * on-behalf endpoint to enforce that the supplied machine actually
 * belongs to the supplied customer.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.10.
 */
final class MachineBelongsToCustomer implements ValidationRule
{
    public function __construct(
        private readonly int $customerId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $exists = Machine::query()
            ->where('id', (int) $value)
            ->where('user_id', $this->customerId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $fail(__('admin.maintenance.machine_not_owned'));
        }
    }
}
