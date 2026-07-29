<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\AuditLogs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/audit-logs.
 *
 * Authorisation runs in the controller via
 * `$this->authorize('audit-logs.view')` against the gate registered in
 * AppServiceProvider — see docs/architecture/admin-audit-templates-dashboard.md
 * § 6.2.1. This Form Request only shapes the filter payload.
 *
 * `action` is an alias for `log_name`. When both arrive in the same query
 * string, `action` wins and `log_name` is ignored — surfaced as a single
 * normalised `log_name` filter to the service.
 *
 * `subject_type` accepts either a fully-qualified class name (e.g.
 * `App\Models\Order`) or a short alias (`order`, `machine`, etc.) —
 * resolved server-side by the service.
 */
final class ListAuditLogsRequest extends FormRequest
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
            'actor_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:64'],
            'log_name' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:today'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('admin.audit-logs.date_range_invalid'),
            'actor_id.exists' => __('admin.audit-logs.actor_unknown'),
        ];
    }
}
