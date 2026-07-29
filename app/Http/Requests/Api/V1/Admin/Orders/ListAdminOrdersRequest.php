<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use App\Enums\DashboardPeriod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/orders.
 *
 * Permission-level authorisation (`orders.view`) is asserted by the
 * controller via `$this->authorize('viewAnyAdmin', Order::class)`. The
 * `include_trashed` sub-permission gate (`orders.cancel-any`) is also
 * controller-side because it depends on the parsed boolean.
 *
 * See docs/architecture/admin-orders.md § 3.1.
 */
final class ListAdminOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise `status` to an array so the service can always whereIn.
     * Also expand a `period` shorthand into explicit `date_from` / `date_to`.
     */
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
            'status.*' => ['string', 'in:'.implode(',', OrderStatus::values())],
            'search' => ['nullable', 'string', 'min:1', 'max:160'],
            'period' => ['nullable', 'string', 'in:'.implode(',', DashboardPeriod::values())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', PaymentMethod::values())],
            'country' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:created_at,total_amount,status,placed_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('admin.orders.date_range_invalid'),
            'country.exists' => __('admin.orders.country_unknown'),
            'status.*.in' => __('admin.orders.status_invalid'),
            'period.in' => __('admin.orders.period_invalid'),
        ];
    }
}
