<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/me/machines — query-string validation.
 *
 * See docs/architecture/machines-maintenance.md § 3.2.
 */
final class ListMachinesRequest extends FormRequest
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
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'needs_maintenance' => ['nullable', 'boolean'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:rented,purchased'],
            'sort' => [
                'nullable',
                'string',
                'in:installed_at,next_maintenance_due_at,created_at,serial_number,product_name,name',
            ],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
