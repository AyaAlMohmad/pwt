<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Support;

use App\Enums\FileType;
use App\Models\User;
use App\Rules\OwnedFile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/support-tickets/{id}/reply.
 *
 * Admin replies on Phase 19. The body may be a customer-visible reply
 * (`is_internal=false`) or an internal note (`is_internal=true`). Up
 * to 5 pre-uploaded `support_attachment` files (owned by the
 * authenticated admin) may be referenced.
 *
 * Terminal-status rejection for `is_internal=false` replies surfaces
 * from the service as 409 `ERR_TICKET_TERMINAL` — not validated here
 * because it requires the resolved ticket model.
 *
 * See docs/architecture/admin-support.md § 3.4.
 */
final class ReplyToSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Coerce "true"/"1"/"false"/"0" strings to real booleans.
        if ($this->has('is_internal')) {
            $raw = $this->input('is_internal');
            if (is_string($raw)) {
                $coerced = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($coerced !== null) {
                    $this->merge(['is_internal' => $coerced]);
                }
            }
        }

        // Trim the body so a whitespace-only message fails the
        // `min:1` rule cleanly.
        $body = $this->input('body');
        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $admin */
        $admin = $this->user();
        $adminId = $admin !== null ? (int) $admin->id : 0;

        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachment_file_ids' => ['nullable', 'array', 'max:5'],
            'attachment_file_ids.*' => [
                'integer',
                'distinct',
                new OwnedFile($adminId, FileType::SupportAttachment),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => __('admin.support.reply_body_required'),
            'body.min' => __('admin.support.reply_body_min'),
            'body.max' => __('admin.support.reply_body_max'),
            'attachment_file_ids.max' => __('admin.support.attachments_max'),
            'attachment_file_ids.*.distinct' => __('admin.support.attachments_invalid'),
        ];
    }
}
