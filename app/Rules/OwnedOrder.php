<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Order;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that an order id refers to a row owned by the currently
 * authenticated user AND not soft-deleted.
 *
 * Used on `POST /api/v1/payments/initiate` for `order_id`. Per
 * docs/architecture/payments.md § 3.1, ownership failure surfaces as 422
 * (so attackers don't infer existence) — the service layer additionally
 * re-checks ownership and emits 403 if the route ever changes.
 */
final class OwnedOrder implements ValidationRule
{
    public function __construct(
        private readonly int $userId,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $exists = Order::query()
            ->where('id', (int) $value)
            ->where('user_id', $this->userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $fail(__('payments.order_not_owned'));
        }
    }
}
