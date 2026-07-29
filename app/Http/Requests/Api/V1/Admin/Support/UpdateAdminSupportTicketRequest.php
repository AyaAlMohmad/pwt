<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Support;

use App\Enums\SupportTicketPriority;
use App\Rules\IsAdminInRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Body validation for PATCH /api/v1/admin/support-tickets/{id}.
 *
 * Each mutation field (`status`, `priority`, `assigned_to_user_id`) is
 * individually optional but at least one must be supplied. Status
 * target `open` is rejected outright (initial state only — reopening
 * is Phase 2 per architecture § 9.7).
 *
 * Server-managed fields are explicitly `prohibited` so admin tooling
 * gets a 422 (not silent acceptance) when sending immutable columns.
 *
 * See docs/architecture/admin-support.md § 3.3.
 */
final class UpdateAdminSupportTicketRequest extends FormRequest
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
            'status' => ['sometimes', 'string', 'in:assigned,in_progress,resolved,closed,cancelled'],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', SupportTicketPriority::values())],
            'assigned_to_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:users,id',
                new IsAdminInRole(['support', 'super_admin']),
            ],
            'resolution_summary' => ['required_if:status,resolved', 'nullable', 'string', 'min:5', 'max:2000'],
            'cancellation_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'min:3', 'max:500'],
            'note' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],

            // Server-managed / immutable. The customer-submitted subject
            // and category cannot be edited post-create (protects audit
            // trail — see architecture § 9.6).
            'public_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'subject' => ['prohibited'],
            'category' => ['prohibited'],
            'related_order_id' => ['prohibited'],
            'related_machine_id' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'first_response_at' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
            'last_message_at' => ['prohibited'],
            'last_customer_view_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            $hasStatus = array_key_exists('status', $data) && is_string($data['status']) && $data['status'] !== '';
            $hasPriority = array_key_exists('priority', $data) && is_string($data['priority']) && $data['priority'] !== '';
            $hasAssignment = array_key_exists('assigned_to_user_id', $data);

            if (! $hasStatus && ! $hasPriority && ! $hasAssignment) {
                $v->errors()->add(
                    '_',
                    (string) __('admin.support.empty_update_body'),
                );

                return;
            }

            // status=assigned REQUIRES assigned_to_user_id (non-null).
            if ($hasStatus && $data['status'] === 'assigned') {
                $assignee = $data['assigned_to_user_id'] ?? null;
                if ($assignee === null || $assignee === '') {
                    $v->errors()->add(
                        'assigned_to_user_id',
                        (string) __('admin.support.assignee_required_for_assigned'),
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __('admin.support.status_invalid'),
            'priority.in' => __('admin.support.priority_invalid'),
            'resolution_summary.required_if' => __('admin.support.resolution_required'),
            'cancellation_reason.required_if' => __('admin.support.cancellation_reason_required'),
            'public_id.prohibited' => __('admin.support.forbidden_field'),
            'user_id.prohibited' => __('admin.support.forbidden_field'),
            'subject.prohibited' => __('admin.support.forbidden_field'),
            'category.prohibited' => __('admin.support.forbidden_field'),
            'related_order_id.prohibited' => __('admin.support.forbidden_field'),
            'related_machine_id.prohibited' => __('admin.support.forbidden_field'),
            'opened_at.prohibited' => __('admin.support.forbidden_field'),
            'first_response_at.prohibited' => __('admin.support.forbidden_field'),
            'closed_at.prohibited' => __('admin.support.forbidden_field'),
            'cancelled_at.prohibited' => __('admin.support.forbidden_field'),
            'cancelled_by_user_id.prohibited' => __('admin.support.forbidden_field'),
            'last_message_at.prohibited' => __('admin.support.forbidden_field'),
            'last_customer_view_at.prohibited' => __('admin.support.forbidden_field'),
        ];
    }
}
