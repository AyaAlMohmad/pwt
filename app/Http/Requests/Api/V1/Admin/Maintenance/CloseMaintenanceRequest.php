<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Maintenance;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Body validation for POST /api/v1/admin/maintenance-requests/{id}/close.
 *
 * Tightened per orchestrator decision 5: `completed_at` must be
 * `<= today`, `>= machine.installed_at`, and `>= request.created_at`.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.9.
 */
final class CloseMaintenanceRequest extends FormRequest
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
            'resolution_notes' => ['required', 'string', 'min:5', 'max:2000'],
            'completed_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],

            // Server-managed — reject explicit attempts.
            'status' => ['prohibited'],
            'assigned_to_user_id' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $completedRaw = $this->input('completed_at');
            if (! is_string($completedRaw) || $completedRaw === '') {
                return;
            }

            $id = (int) $this->route('id');
            if ($id <= 0) {
                return;
            }

            $request = MaintenanceRequest::query()
                ->withTrashed()
                ->with('machine')
                ->find($id);
            if ($request === null) {
                return;
            }

            try {
                $completed = Carbon::parse($completedRaw)->startOfDay();
            } catch (\Throwable) {
                return;
            }

            $createdAt = $request->created_at->copy()->startOfDay();
            $installedAt = $request->machine?->installed_at->copy()->startOfDay();

            if ($completed->lessThan($createdAt)) {
                $v->errors()->add('completed_at', __('admin.maintenance.completed_at_invalid'));

                return;
            }
            if ($installedAt !== null && $completed->lessThan($installedAt)) {
                $v->errors()->add('completed_at', __('admin.maintenance.completed_at_invalid'));
            }
        });
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
