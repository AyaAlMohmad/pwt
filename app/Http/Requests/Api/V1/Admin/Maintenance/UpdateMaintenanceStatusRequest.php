<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Maintenance;

use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for PATCH /api/v1/admin/maintenance-requests/{id}/status.
 *
 * Allowed values: `in_progress`, `cancelled`. `assigned` and
 * `completed` are intentionally rejected — admins must use the
 * dedicated `/assign` and `/close` endpoints so the side-effects
 * (assigned_to_user_id capture, resolution_notes, log row creation,
 * scheduler refresh) are properly captured.
 *
 * `reason` is required when `status=cancelled` and persisted as
 * `cancellation_reason`.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.8.
 */
final class UpdateMaintenanceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        // Numeric codes are normalised to the enum value before the
        // rules run: 1=new 2=assigned 3=in_progress 4=completed
        // 5=cancelled. Unresolvable codes fall through untouched so
        // the `in:` rule surfaces a 422.
        if (is_int($status) || (is_string($status) && ctype_digit($status))) {
            $resolved = MaintenanceStatus::tryFromCode((int) $status);
            if ($resolved !== null) {
                $this->merge(['status' => $resolved->value]);
            }

            return;
        }

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
            // Accept the full enum range here so the service can return
            // the specific 409 codes (ERR_USE_ASSIGN_ENDPOINT /
            // ERR_USE_CLOSE_ENDPOINT) rather than a generic 422.
            'status' => ['required', 'string', 'in:in_progress,cancelled,assigned,completed,new'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],

            // Server-managed — reject explicit attempts.
            'assigned_to_user_id' => ['prohibited'],
            'resolution_notes' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to_user_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'resolution_notes.prohibited' => __('admin.maintenance.forbidden_field'),
            'cancelled_by_user_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'closed_at.prohibited' => __('admin.maintenance.forbidden_field'),
        ];
    }
}
