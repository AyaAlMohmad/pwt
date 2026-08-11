<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use App\Enums\OrderStatus;
use App\Services\Orders\OrderStatusTransitionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for PATCH /api/v1/admin/orders/{id}/status.
 *
 * The Form Request validates that `status` is a known enum value;
 * the state machine ({@see OrderStatusTransitionService})
 * rejects forbidden transitions with 409 ERR_INVALID_STATUS_TRANSITION.
 *
 * The service also intercepts `status === 'scheduled'` and redirects
 * callers to the dedicated /schedule-installation endpoint with 409
 * ERR_USE_SCHEDULE_ENDPOINT (see § 3.2 / § 9.7).
 *
 * Monetary fields are `prohibited` — admins never set money from the
 * request body (per CLAUDE.md § 9 server-recomputation rule).
 *
 * See docs/architecture/admin-orders.md § 3.2.
 */
final class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (is_string($status) && $status !== '') {
            $this->merge(['status' => strtolower($status)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::values())],
            'note' => ['nullable', 'string', 'min:3', 'max:1000'],

            // Per-product maintenance intervals set when status becomes installed.
            'maintenance_intervals' => ['required_if:status,installed', 'prohibited_unless:status,installed', 'array', 'min:1'],
            'maintenance_intervals.*' => ['required', 'array'],
            'maintenance_intervals.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'maintenance_intervals.*.filter_change_interval_months' => ['required', 'integer', 'min:1', 'max:60'],

            // Money fields are server-computed; reject explicit attempts.
            'total_amount' => ['prohibited'],
            'subtotal_amount' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'shipping_amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'actor_type' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => __('admin.orders.status_required'),
            'status.in' => __('admin.orders.status_invalid'),
            'note.min' => __('admin.orders.note_min'),
            'note.max' => __('admin.orders.note_max_transition'),
            'total_amount.prohibited' => __('admin.orders.forbidden_money_field'),
            'subtotal_amount.prohibited' => __('admin.orders.forbidden_money_field'),
            'discount_amount.prohibited' => __('admin.orders.forbidden_money_field'),
            'tax_amount.prohibited' => __('admin.orders.forbidden_money_field'),
            'shipping_amount.prohibited' => __('admin.orders.forbidden_money_field'),
            'currency.prohibited' => __('admin.orders.forbidden_money_field'),
            'actor_type.prohibited' => __('admin.orders.forbidden_money_field'),
            'actor_id.prohibited' => __('admin.orders.forbidden_money_field'),
            'cancelled_at.prohibited' => __('admin.orders.forbidden_money_field'),
            'confirmed_at.prohibited' => __('admin.orders.forbidden_money_field'),
        ];
    }
}
