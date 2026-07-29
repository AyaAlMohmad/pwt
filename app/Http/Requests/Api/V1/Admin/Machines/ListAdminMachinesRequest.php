<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use App\Enums\DashboardPeriod;
use App\Enums\MachineSource;
use App\Enums\ProductTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Query-string validation for GET /api/v1/admin/machines.
 *
 * Permission-level authorisation (`machines.view`) is asserted by the
 * controller via `$this->authorize('viewAnyAdmin', Machine::class)`.
 * The `include_trashed` sub-permission gate is also controller-side
 * because it depends on the parsed boolean.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.1.
 */
final class ListAdminMachinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            'search' => ['nullable', 'string', 'min:1', 'max:160'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'needs_maintenance' => ['nullable', 'boolean'],
            'within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:rented,purchased'],
            'source' => ['nullable', 'string', 'in:'.implode(',', MachineSource::values())],
            'term' => ['nullable', 'string', 'in:'.implode(',', ProductTerm::values())],
            'include_trashed' => ['nullable', 'boolean'],
            'period' => ['nullable', 'string', 'in:'.implode(',', DashboardPeriod::values())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', 'in:created_at,installed_at,next_maintenance_due_at,serial_number'],
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
        ];
    }
}
