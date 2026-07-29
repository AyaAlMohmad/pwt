<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Maintenance;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Rules\IsAdminInRole;
use App\Rules\IsCustomer;
use App\Rules\MachineBelongsToCustomer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/maintenance-requests.
 *
 * Admins submit on behalf of a customer. The body uses `customer_id`
 * (not `user_id`) to make on-behalf semantics explicit. Admins may set
 * `high` / `urgent` priority — no clamp (unlike the customer surface).
 *
 * NOTE: media_file_ids are intentionally NOT accepted — customer
 * attaches media later (orchestrator decision 2).
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.6.
 */
final class SubmitOnBehalfMaintenanceRequest extends FormRequest
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
        $customerId = (int) ($this->input('customer_id') ?? 0);

        return [
            'customer_id' => ['required', 'integer', 'exists:users,id', new IsCustomer],
            'machine_id' => array_merge(
                ['required_without:machine_ids', 'integer', 'exists:machines,id'],
                $customerId > 0 ? [new MachineBelongsToCustomer($customerId)] : [],
            ),
            'machine_ids' => ['required_without:machine_id', 'array', 'min:1', 'max:50'],
            'machine_ids.*' => array_merge(
                ['integer', 'exists:machines,id'],
                $customerId > 0 ? [new MachineBelongsToCustomer($customerId)] : [],
            ),
            'issue_type' => ['required', 'string', 'in:'.implode(',', MaintenanceIssueType::values())],
            'description' => ['required', 'string', 'min:5', 'max:2000'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', MaintenancePriority::values())],
            'preferred_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.now()->addDays(180)->toDateString()],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id', new IsAdminInRole('operations')],

            // Server-managed — reject explicit attempts.
            'id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'resolution_notes' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
            'media_file_ids' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.prohibited' => __('admin.maintenance.forbidden_field'),
            'public_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'user_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'status.prohibited' => __('admin.maintenance.forbidden_field'),
            'resolution_notes.prohibited' => __('admin.maintenance.forbidden_field'),
            'closed_at.prohibited' => __('admin.maintenance.forbidden_field'),
            'cancellation_reason.prohibited' => __('admin.maintenance.forbidden_field'),
            'cancelled_by_user_id.prohibited' => __('admin.maintenance.forbidden_field'),
            'media_file_ids.prohibited' => __('admin.maintenance.forbidden_field'),
            'created_at.prohibited' => __('admin.maintenance.forbidden_field'),
            'updated_at.prohibited' => __('admin.maintenance.forbidden_field'),
            'deleted_at.prohibited' => __('admin.maintenance.forbidden_field'),
        ];
    }
}
