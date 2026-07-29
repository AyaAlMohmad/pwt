<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\AccountType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/users.
 *
 * Permission-level authorisation (`users.view`) is asserted by the
 * controller via `$this->authorize('viewAny', User::class)`. The
 * `include_trashed` sub-permission gate (`users.deactivate`) is also
 * controller-side because it depends on the parsed boolean value.
 *
 * See docs/architecture/admin-users.md § 3.1.
 */
final class ListAdminUsersRequest extends FormRequest
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
            'role' => [
                'nullable',
                'string',
                'in:'.implode(
                    ',',
                    array_merge(UserRole::adminValues(), [UserRole::Customer->value]),
                ),
            ],
            'is_active' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'in:'.implode(',', AccountType::values())],
            'country' => ['nullable', 'string', 'size:2'],
            'emirate' => ['nullable', 'string', 'max:120'],
            'include_trashed' => ['nullable', 'boolean'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sort' => ['nullable', 'string', 'in:created_at,last_login_at,name,email'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
