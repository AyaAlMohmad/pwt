<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/machines/due-maintenance.
 *
 * Sort is forced to `next_maintenance_due_at ASC, id ASC` by the
 * service — this is a workflow endpoint, not a generic list.
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.2.
 */
final class DueMaintenanceRequest extends FormRequest
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
            'within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
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
