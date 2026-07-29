<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/reports/{type}/export.
 *
 * Accepts the union of all four read-endpoint filter sets plus `format`.
 * Unknown filters for the selected type are silently ignored by the
 * service dispatch (`ReportBuilder` only consumes its own filter shape).
 *
 * `page` and `per_page` are NOT accepted — exports are streamed with a
 * hard 10 000 row cap (see § 3.5 / § 3.6 of the architecture doc).
 *
 * See docs/architecture/admin-reports.md § 3.5.
 */
final class ExportReportRequest extends FormRequest
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
            'format' => ['required', 'string', 'in:csv,xlsx'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$threeYearsAgo, 'before_or_equal:today'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'country' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'term' => ['nullable', 'string', 'in:'.implode(',', ProductTerm::values())],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'status' => ['nullable', 'array'],
            'status.*' => ['string'],
            // Status values are validated downstream by the type dispatch
            // because the allowed set differs between orders + maintenance.
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'issue_type' => ['nullable', 'string', 'in:'.implode(',', MaintenanceIssueType::values())],
            'priority' => ['nullable', 'string', 'in:'.implode(',', MaintenancePriority::values())],
            'has_orders' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.required' => __('admin.reports.format_required'),
            'format.in' => __('admin.reports.format_invalid'),
            'date_to.after_or_equal' => __('admin.reports.date_range_invalid'),
            'country.exists' => __('admin.reports.country_unknown'),
            'product_id.exists' => __('admin.reports.product_unknown'),
        ];
    }

    /**
     * Map the type → allowed status values so the service can re-validate
     * the status filter against the right enum.
     *
     * @return list<string>
     */
    public static function allowedStatusesFor(string $type): array
    {
        return match ($type) {
            'orders' => OrderStatus::values(),
            'maintenance' => MaintenanceStatus::values(),
            default => [],
        };
    }
}
