<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/reports/maintenance.
 *
 * See docs/architecture/admin-reports.md § 3.3.
 */
final class MaintenanceReportRequest extends FormRequest
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

        $country = $this->input('country');
        if (is_string($country) && $country !== '') {
            $this->merge(['country' => strtoupper($country)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $threeYearsAgo = Carbon::today('Asia/Dubai')->subYears(3)->toDateString();

        return [
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$threeYearsAgo, 'before_or_equal:today'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:'.implode(',', MaintenanceStatus::values())],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'issue_type' => ['nullable', 'string', 'in:'.implode(',', MaintenanceIssueType::values())],
            'priority' => ['nullable', 'string', 'in:'.implode(',', MaintenancePriority::values())],
            'country' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at,status,priority'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('admin.reports.date_range_invalid'),
            'country.exists' => __('admin.reports.country_unknown'),
            'status.*.in' => __('admin.reports.status_invalid'),
            'assigned_to_user_id.exists' => __('admin.reports.assignee_unknown'),
        ];
    }
}
