<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/orders/{id}/cancel.
 *
 * Distinct from the customer-side `CancelOrderRequest`:
 *  - field name is `reason` (vs `cancellation_reason` for customer)
 *  - max length 1000 (vs 500) — admin cancels often include detailed
 *    operational context.
 *
 * Source-state validity is enforced inside the transition service.
 *
 * See docs/architecture/admin-orders.md § 3.3.
 */
final class AdminCancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('admin.orders.reason_required'),
            'reason.min' => __('admin.orders.reason_min'),
            'reason.max' => __('admin.orders.reason_max'),
        ];
    }
}
