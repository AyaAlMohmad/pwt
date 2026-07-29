<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Machine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a machine id refers to an active, non-soft-deleted row
 * owned by the currently-authenticated user.
 *
 * Used on `POST /api/v1/maintenance-requests` for `machine_id`. Failure
 * returns 422 with `ERR_MACHINE_NOT_OWNED` — NOT 403/404 — to avoid
 * leaking which machine ids exist for other customers (the path is a
 * field-validation error from the customer's perspective).
 *
 * See docs/architecture/machines-maintenance.md § 5.1.
 */
final class OwnedMachine implements ValidationRule
{
    public function __construct(
        private readonly int $userId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            // The `integer` rule has already reported the type failure;
            // do not pile on a second message.
            return;
        }

        $exists = Machine::query()
            ->where('id', (int) $value)
            ->where('user_id', $this->userId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $fail(__('maintenance.machine_not_owned'));
        }
    }
}
