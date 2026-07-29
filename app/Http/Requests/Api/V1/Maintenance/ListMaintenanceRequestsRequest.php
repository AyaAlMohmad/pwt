<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/maintenance-requests — query-string validation.
 *
 * The controller scopes the query to `user_id = auth.id`; this Form
 * Request only validates the filter shape.
 *
 * See docs/architecture/machines-maintenance.md § 3.3.
 */
final class ListMaintenanceRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $statusValues = implode(',', MaintenanceStatus::values());

        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.$statusValues],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', 'in:created_at,status,preferred_date'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
