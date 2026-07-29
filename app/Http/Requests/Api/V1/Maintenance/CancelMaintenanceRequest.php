<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/maintenance-requests/{id}/cancel — body validation.
 *
 * Allowed only when the request is in `new` status (orchestrator
 * decision to ship the customer-cancel endpoint in Phase 10). Status
 * validity is enforced by the service.
 */
#[OA\RequestBody(
    request: 'CancelMaintenanceRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/CancelMaintenanceRequest'),
)]
final class CancelMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason.required' => __('maintenance.cancel_reason_required'),
            'cancellation_reason.min' => __('maintenance.cancel_reason_min'),
            'cancellation_reason.max' => __('maintenance.cancel_reason_max'),
        ];
    }
}
