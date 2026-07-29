<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Templates;

use App\Enums\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/notification-templates.
 *
 * Authorisation (`$this->authorize('viewAny', NotificationTemplate::class)`)
 * runs in the controller. This Form Request only shapes the filter payload.
 *
 * See docs/architecture/admin-audit-templates-dashboard.md § 3.2.
 */
final class ListTemplatesRequest extends FormRequest
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
            'channel' => ['nullable', 'string', 'in:'.implode(',', NotificationChannel::values())],
            'key' => ['nullable', 'string', 'max:64'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:key,channel,updated_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
