<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Support;

use App\Enums\FileType;
use App\Enums\SupportTicketCategory;
use App\Models\User;
use App\Rules\OwnedFile;
use App\Rules\OwnedMachine;
use App\Rules\OwnedOrder;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/support-tickets — body validation.
 *
 * Server-managed (rejected if supplied):
 *   - priority, status, assigned_to_user_id, resolution_summary,
 *     closed_at, cancelled_at, cancelled_by_user_id, first_response_at,
 *     opened_at, user_id, public_id
 *
 * Customers ALWAYS file at `priority=medium`. Admin Phase 19 elevates
 * via a separate endpoint. See docs/architecture/support.md § 4.1.
 */
#[OA\RequestBody(
    request: 'CreateSupportTicketRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/CreateSupportTicketRequest'),
)]
final class CreateSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Defensive coercion: trim subject, lowercase category, coerce a
     * scalar `attachment_file_ids` into a one-element array.
     */
    protected function prepareForValidation(): void
    {
        $patches = [];

        if (is_string($this->input('subject'))) {
            $patches['subject'] = trim((string) $this->input('subject'));
        }

        if (is_string($this->input('category'))) {
            $patches['category'] = strtolower(trim((string) $this->input('category')));
        }

        $attachments = $this->input('attachment_file_ids');
        if ($attachments !== null && ! is_array($attachments)) {
            $patches['attachment_file_ids'] = [$attachments];
        }

        if ($patches !== []) {
            $this->merge($patches);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $userId = $user?->getAuthIdentifier();
        $userIdInt = is_numeric($userId) ? (int) $userId : 0;

        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'category' => [
                'required',
                'string',
                'in:'.implode(',', SupportTicketCategory::values()),
            ],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'related_order_id' => [
                'nullable',
                'integer',
                'exists:orders,id',
                new OwnedOrder($userIdInt),
            ],
            'related_machine_id' => [
                'nullable',
                'integer',
                'exists:machines,id',
                new OwnedMachine($userIdInt),
            ],
            'attachment_file_ids' => ['nullable', 'array', 'max:5'],
            'attachment_file_ids.*' => [
                'integer',
                'distinct',
                'exists:files,id',
                new OwnedFile($userIdInt, FileType::SupportAttachment),
            ],

            // Forbidden — server-managed.
            'priority' => ['prohibited'],
            'status' => ['prohibited'],
            'assigned_to_user_id' => ['prohibited'],
            'resolution_summary' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'first_response_at' => ['prohibited'],
            'opened_at' => ['prohibited'],
            'user_id' => ['prohibited'],
            'public_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('support.subject_required'),
            'subject.min' => __('support.subject_min'),
            'subject.max' => __('support.subject_max'),
            'category.required' => __('support.category_required'),
            'category.in' => __('support.category_invalid'),
            'message.required' => __('support.message_required'),
            'message.min' => __('support.message_min'),
            'message.max' => __('support.message_max'),
            'related_order_id.exists' => __('support.related_order_not_owned'),
            'related_machine_id.exists' => __('support.related_machine_not_owned'),
            'attachment_file_ids.max' => __('support.attachments_max'),
            'attachment_file_ids.*.exists' => __('support.file_not_owned'),
            'attachment_file_ids.*.distinct' => __('support.attachments_invalid'),
            'priority.prohibited' => __('support.field_forbidden'),
            'status.prohibited' => __('support.field_forbidden'),
            'assigned_to_user_id.prohibited' => __('support.field_forbidden'),
            'resolution_summary.prohibited' => __('support.field_forbidden'),
            'closed_at.prohibited' => __('support.field_forbidden'),
            'cancelled_at.prohibited' => __('support.field_forbidden'),
            'cancelled_by_user_id.prohibited' => __('support.field_forbidden'),
            'cancellation_reason.prohibited' => __('support.field_forbidden'),
            'first_response_at.prohibited' => __('support.field_forbidden'),
            'opened_at.prohibited' => __('support.field_forbidden'),
            'user_id.prohibited' => __('support.field_forbidden'),
            'public_id.prohibited' => __('support.field_forbidden'),
        ];
    }
}
