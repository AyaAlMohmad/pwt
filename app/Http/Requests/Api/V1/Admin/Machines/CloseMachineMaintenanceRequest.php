<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/machines/{id}/maintenance-close.
 *
 * Mirrors {@see \App\Http\Requests\Api\V1\Admin\Maintenance\CloseMaintenanceRequest}
 * but targets the machine's latest open request instead of an explicit request id.
 */
final class CloseMachineMaintenanceRequest extends FormRequest
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
            'resolution_notes' => ['nullable', 'string', 'min:5', 'max:2000'],
            'completed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],

            // Server-managed — reject explicit attempts.
            'status' => ['prohibited'],
            'assigned_to_user_id' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => __('admin.maintenance.forbidden_field'),
            'assigned_to_user_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'cancellation_reason.prohibited' => __('admin.maintenance.forbidden_field'),
            'closed_at.prohibited' => __('admin.maintenance.forbidden_field'),
        ];
    }
}
