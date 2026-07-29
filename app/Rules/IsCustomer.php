<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\AccountType;
use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a `user_id` references an ACTIVE, NON-TRASHED user
 * that is a customer (either via role or via account_type).
 *
 * Used by the admin machine create endpoint and the admin maintenance
 * on-behalf endpoint to ensure the target user is actually a customer
 * (not an admin, not a deactivated account).
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.10.
 */
final class IsCustomer implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value)) {
            $fail(__('admin.maintenance.user_not_customer'));

            return;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('id', (int) $value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($user === null) {
            $fail(__('admin.maintenance.user_not_customer'));

            return;
        }

        $isCustomerRole = $user->hasRole(UserRole::Customer->value);
        $hasAccountType = in_array(
            $user->account_type ?? '',
            [AccountType::Company->value, AccountType::Individual->value],
            true
        );

        if (! $isCustomerRole && ! $hasAccountType) {
            $fail(__('admin.maintenance.user_not_customer'));
        }
    }
}
