<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a `user_id` references an ACTIVE, NON-TRASHED user
 * that holds at least one of the supplied admin roles.
 *
 * Used by:
 *   - Phase 18 admin schedule-installation / assign-maintenance — MVP
 *     reuses the `operations` role pending the dedicated `technician`
 *     role in Phase 2 (per CLAUDE.md § 17 and
 *     docs/architecture/admin-orders.md § 9.5).
 *   - Phase 19 admin support ticket assign — `['support', 'super_admin']`.
 *
 * Accepts either a single role string (legacy) or a list of role
 * strings (Phase 19 onwards). At least one must match.
 */
final class IsAdminInRole implements ValidationRule
{
    /** @var list<string> */
    private readonly array $roles;

    /**
     * @param  string|list<string>  $role
     */
    public function __construct(string|array $role)
    {
        $this->roles = is_array($role)
            ? array_map(static fn ($r): string => (string) $r, $role)
            : [$role];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            // The base `nullable` rule already short-circuits empty; this
            // is defensive for direct invocation.
            return;
        }

        if (! is_numeric($value)) {
            $fail($this->errorMessage());

            return;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('id', (int) $value)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($user === null) {
            $fail($this->errorMessage());

            return;
        }

        if (! $user->hasAnyRole($this->roles)) {
            $fail($this->errorMessage());
        }
    }

    /**
     * Surface the support-specific message when the rule was constructed
     * for the support context. Defaults to the maintenance/orders copy
     * for backward compatibility with Phase 17/18 call sites.
     */
    private function errorMessage(): string
    {
        if (in_array('support', $this->roles, true)) {
            return (string) __('admin.support.invalid_assignee');
        }

        return (string) __('admin.orders.technician_invalid');
    }
}
