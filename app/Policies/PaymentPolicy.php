<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Ownership-only authorisation for {@see Payment}.
 *
 * The denormalised `payments.user_id` column makes this check
 * index-only — see docs/architecture/payments.md § 6.3 and § 10.1.
 *
 * Per CLAUDE.md § 7.3 ownership failure surfaces as **403 not 404**
 * (shaped by the global exception handler).
 */
final class PaymentPolicy
{
    public function view(User $actor, Payment $payment): bool
    {
        return $payment->user_id === $actor->id;
    }

    /**
     * Customer-side payment creation is gated by Sanctum + the
     * `payments.initiate` permission check (deferred to Phase 9.x —
     * currently the controller delegates to `authorize` and the
     * customer role passes). Admin manual COD confirmation lands in
     * Phase 17.
     */
    public function create(User $actor): bool
    {
        return true;
    }
}
