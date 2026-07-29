<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Maintenance;

use App\Enums\DashboardPeriod;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/maintenance-requests.
 *
 * Permission-level authorisation (`maintenance.view`) is asserted by
 * the controller. The `include_trashed` sub-permission gate
 * (`super_admin` only) is also controller-side because it depends on
 * the parsed boolean.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.5.
 */
final class ListAdminMaintenanceRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        if (is_string($status) && $status !== '') {
            $this->merge(['status' => [$status]]);
        }

        $period = $this->input('period');
        if (is_string($period) && $period !== '') {
            $now = Carbon::now(config('app.timezone', 'Asia/Dubai'));
            $parsed = DashboardPeriod::tryFrom($period);

            if ($parsed !== null) {
                switch ($parsed) {
                    case DashboardPeriod::Yesterday:
                        $from = $now->copy()->subDay()->startOfDay();
                        $to = $now->copy()->subDay()->endOfDay();
                        break;
                    case DashboardPeriod::Last7Days:
                        $from = $now->copy()->subDays(6)->startOfDay();
                        $to = $now->copy()->endOfDay();
                        break;
                    case DashboardPeriod::ThisWeek:
                        $from = $now->copy()->startOfWeek();
                        $to = $now->copy()->endOfDay();
                        break;
                    case DashboardPeriod::ThisMonth:
                        $from = $now->copy()->startOfMonth();
                        $to = $now->copy()->endOfDay();
                        break;
                    case DashboardPeriod::LastMonth:
                        $from = $now->copy()->subMonth()->startOfMonth();
                        $to = $now->copy()->subMonth()->endOfMonth();
                        break;
                    case DashboardPeriod::Today:
                    default:
                        $from = $now->copy()->startOfDay();
                        $to = $now->copy()->endOfDay();
                        break;
                }

                $this->merge([
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:'.implode(',', MaintenanceStatus::values())],
            'priority' => ['nullable', 'string', 'in:'.implode(',', MaintenancePriority::values())],
            'issue_type' => ['nullable', 'string', 'in:'.implode(',', MaintenanceIssueType::values())],
            'assigned_to_user_id' => ['nullable', 'integer', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'machine_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'machine_ids.*' => ['integer', 'exists:machines,id'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'search' => ['nullable', 'string', 'min:1', 'max:160'],
            'period' => ['nullable', 'string', 'in:'.implode(',', DashboardPeriod::values())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:created_at,priority,status,preferred_date'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'country_code.exists' => __('admin.orders.country_unknown'),
            'date_to.after_or_equal' => __('admin.orders.date_range_invalid'),
            'period.in' => __('admin.orders.period_invalid'),
        ];
    }
}
