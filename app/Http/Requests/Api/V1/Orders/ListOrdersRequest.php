<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Orders;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/orders — query-string validation.
 *
 * The controller scopes the query to `user_id = auth.id` regardless;
 * this request only validates the filter shape.
 *
 * See docs/architecture/orders.md § 3.2.
 */
final class ListOrdersRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', OrderStatus::values())],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', 'in:created_at,total_amount'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
