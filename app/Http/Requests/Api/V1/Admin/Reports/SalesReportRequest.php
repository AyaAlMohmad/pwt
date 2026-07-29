<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Reports;

use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Services\Reports\ReportBuilder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/reports/sales.
 *
 * Permission + per-type gate are controller-side; this request only
 * shapes the filter payload. The default date range (last 30 days) is
 * injected by {@see ReportBuilder::resolveDateRange()}
 * AFTER validation so a bare request passes cleanly.
 *
 * Cross-field: window cannot exceed 366 days — see
 * docs/architecture/admin-reports.md § 3.1.
 */
final class SalesReportRequest extends FormRequest
{
    private const MAX_WINDOW_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'country' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'term' => ['nullable', 'string', 'in:'.implode(',', ProductTerm::values())],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'bucket' => ['nullable', 'string', 'in:auto,daily,monthly'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at,total_amount,product_code'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');
            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            try {
                $start = Carbon::createFromFormat('Y-m-d', $from);
                $end = Carbon::createFromFormat('Y-m-d', $to);
            } catch (\Throwable) {
                return;
            }

            if ($start === null || $end === null) {
                return;
            }

            if ($start->diffInDays($end, true) > self::MAX_WINDOW_DAYS) {
                $validator->errors()->add(
                    'date_to',
                    __('admin.reports.window_too_wide'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('admin.reports.date_range_invalid'),
            'country.exists' => __('admin.reports.country_unknown'),
            'product_id.exists' => __('admin.reports.product_unknown'),
        ];
    }
}
