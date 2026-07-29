<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/categories.
 *
 * Permission-level authorisation (`categories.view`) is asserted by the
 * controller via `$this->authorize('viewAny', Category::class)`.
 *
 * See docs/architecture/admin-catalog.md § 3.1.
 */
final class ListAdminCategoriesRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'min:1', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:display_order,name,code,created_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
