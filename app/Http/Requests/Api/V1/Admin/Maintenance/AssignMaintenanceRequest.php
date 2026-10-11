<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Maintenance;

use App\Rules\IsAdminInRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for PATCH /api/v1/admin/maintenance-requests/{id}/assign.
 *
 * The `assigned_to_user_id` must reference an active admin holding the
 * `operations` role (MVP technician proxy — orchestrator decision 1).
 * It is nullable: omitting it (or sending null) unassigns the request
 * and returns an `assigned` request to `new`.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.7.
 */
final class AssignMaintenanceRequest extends FormRequest
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
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id', new IsAdminInRole('operations')],
            'note' => ['nullable', 'string', 'min:3', 'max:500'],

            // Server-managed — reject explicit attempts.
            'status' => ['prohibited'],
            'resolution_notes' => ['prohibited'],
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
            'resolution_notes.prohibited' => __('admin.maintenance.forbidden_field'),
            'cancellation_reason.prohibited' => __('admin.maintenance.forbidden_field'),
            'closed_at.prohibited' => __('admin.maintenance.forbidden_field'),
        ];
    }
}
