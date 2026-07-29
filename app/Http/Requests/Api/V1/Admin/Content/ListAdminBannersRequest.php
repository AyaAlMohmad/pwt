<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/banners.
 *
 * Permission-level authorisation (`banners.view`) is asserted by the
 * controller via `$this->authorize('viewAny', Banner::class)`.
 *
 * See docs/architecture/admin-content.md § 1 / § 3.
 */
final class ListAdminBannersRequest extends FormRequest
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
            'placement' => ['nullable', 'string', 'in:hero,promo,sidebar,footer'],
            'is_active' => ['nullable', 'boolean'],
            'starts_before' => ['nullable', 'date'],
            'starts_after' => ['nullable', 'date'],
            'ends_before' => ['nullable', 'date'],
            'ends_after' => ['nullable', 'date'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:display_order,starts_at,ends_at,created_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
