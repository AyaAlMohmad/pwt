<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Support;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for GET /api/v1/admin/support-tickets.
 *
 * Permission-level authorisation (`support.view` via Policy
 * `viewAnyAdmin`) is asserted by the controller. The `include_trashed`
 * sub-permission gate (super_admin only) is also controller-side
 * because it depends on the parsed boolean.
 *
 * See docs/architecture/admin-support.md § 3.1.
 */
final class ListAdminSupportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Convert single-string status into an array so the rules can
        // share one path. Mirrors Phase 18 maintenance convention.
        $status = $this->input('status');
        if (is_string($status) && $status !== '') {
            $this->merge(['status' => [$status]]);
        }
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
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:'.implode(',', SupportTicketStatus::values())],
            'category' => ['nullable', 'string', 'in:'.implode(',', SupportTicketCategory::values())],
            'priority' => ['nullable', 'string', 'in:'.implode(',', SupportTicketPriority::values())],
            'assigned_to_user_id' => ['nullable', 'integer', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_trashed' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:last_message_at,created_at,priority,status,opened_at'],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => __('admin.support.date_range_invalid'),
        ];
    }
}
