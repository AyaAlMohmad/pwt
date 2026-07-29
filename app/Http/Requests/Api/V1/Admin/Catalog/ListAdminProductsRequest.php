<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/products.
 *
 * Permission-level authorisation (`products.view`) is asserted by the
 * controller via `$this->authorize('viewAny', Product::class)`.
 *
 * See docs/architecture/admin-catalog.md § 3.4.
 */
final class ListAdminProductsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'min:1', 'max:160'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_quote_only' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', 'string', 'in:in_stock,out_of_stock,coming_soon,discontinued'],
            'term' => ['nullable', 'string', 'in:buy,rent,foc'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:created_at,name,code,display_order'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
