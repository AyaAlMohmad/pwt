<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use App\Enums\UserRole;
use App\Rules\IsAdminInRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/orders/{id}/schedule-installation.
 *
 * The order's current status (`confirmed`) is checked inside the
 * service — the Form Request only validates the payload shape.
 *
 * `technician_user_id` reuses the `operations` role in MVP; a dedicated
 * `technician` role lands in Phase 2 (see CLAUDE.md § 17).
 *
 * See docs/architecture/admin-orders.md § 3.4.
 */
final class ScheduleInstallationRequest extends FormRequest
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
            // Accept any parseable date format; `after:now` is the
            // load-bearing constraint here.
            // When omitted, falls back to the order's delivery_date + delivery_time_slot.
            'scheduled_for_at' => ['nullable', 'date', 'after:now'],
            'technician_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                new IsAdminInRole(UserRole::Operations->value),
            ],
            'installation_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_for_at.required' => __('admin.orders.scheduled_for_at_required'),
            'scheduled_for_at.date' => __('admin.orders.scheduled_for_at_invalid'),
            'scheduled_for_at.after' => __('admin.orders.scheduled_for_at_future'),
            'installation_notes.max' => __('admin.orders.note_max_transition'),
        ];
    }
}
