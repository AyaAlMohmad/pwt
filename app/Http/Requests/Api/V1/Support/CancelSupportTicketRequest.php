<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Support;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/support-tickets/{id}/cancel — body validation.
 *
 * Allowed only when the ticket is in `open` status (orchestrator
 * decision to ship the customer-cancel endpoint in Phase 12). Status
 * validity is enforced by the service and surfaces as 409.
 *
 * The cancellation reason is required to keep an audit trail of
 * customer intent (see architecture spec § 4.3 — the reason is
 * persisted to the status_history row and on the ticket itself).
 */
#[OA\RequestBody(
    request: 'CancelSupportTicketRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/CancelSupportTicketRequest'),
)]
final class CancelSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('cancellation_reason');
        if (is_string($reason)) {
            $this->merge(['cancellation_reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => __('support.message_required'),
            'cancellation_reason.string' => __('support.message_required'),
            'cancellation_reason.max' => __('support.cancel_reason_max'),
        ];
    }
}
