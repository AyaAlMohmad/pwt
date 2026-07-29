<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Enums\FileType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Models\User;
use App\Rules\OwnedFile;
use App\Rules\OwnedMachine;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/maintenance-requests — body validation.
 *
 * Server-managed (rejected if supplied):
 *   - status, assigned_to_user_id, resolution_notes, closed_at,
 *     cancellation_reason, user_id, public_id, cancelled_by_user_id
 *
 * Server-coerced:
 *   - priority — high/urgent values are silently coerced down to medium
 *     in `prepareForValidation` (customers cap at medium; admins
 *     elevate via Phase 18). Orchestrator decision: silent coercion,
 *     not 422.
 *
 * See docs/architecture/machines-maintenance.md § 3.1.
 */
#[OA\RequestBody(
    request: 'StoreMaintenanceRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/StoreMaintenanceRequestRequest'),
)]
final class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Customer priority coercion + body trimming.
     */
    protected function prepareForValidation(): void
    {
        $patches = [];

        $rawPriority = $this->input('priority');
        if ($rawPriority !== null) {
            $priority = strtolower(trim((string) $rawPriority));
            $allowed = [MaintenancePriority::Low->value, MaintenancePriority::Medium->value];
            // Silently coerce high/urgent down to medium per § 14.6.
            $patches['priority'] = in_array($priority, $allowed, true)
                ? $priority
                : MaintenancePriority::customerCap()->value;
        }

        if (is_string($this->input('issue_type'))) {
            $patches['issue_type'] = strtolower(trim($this->input('issue_type')));
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
            'machine_id' => [
                'required_without:machine_ids',
                'integer',
                'exists:machines,id',
                new OwnedMachine($userIdInt),
            ],
            'machine_ids' => [
                'required_without:machine_id',
                'array',
                'min:1',
                'max:5',
            ],
            'machine_ids.*' => [
                'integer',
                'exists:machines,id',
                new OwnedMachine($userIdInt),
            ],
            'issue_type' => [
                'required',
                'string',
                'in:'.implode(',', MaintenanceIssueType::values()),
            ],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'preferred_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before:+90 days',
            ],
            'preferred_time' => ['nullable', 'string', 'max:16'],
            'priority' => [
                'nullable',
                'string',
                'in:'.MaintenancePriority::Low->value.','.MaintenancePriority::Medium->value,
            ],
            'media_file_ids' => ['nullable', 'array', 'max:5'],
            'media_file_ids.*' => [
                'integer',
                'distinct',
                'exists:files,id',
                new OwnedFile($userIdInt, FileType::MaintenanceMedia),
            ],

            // Forbidden — server-managed.
            'status' => ['prohibited'],
            'assigned_to_user_id' => ['prohibited'],
            'resolution_notes' => ['prohibited'],
            'closed_at' => ['prohibited'],
            'cancellation_reason' => ['prohibited'],
            'cancelled_by_user_id' => ['prohibited'],
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
            'machine_id.required' => __('maintenance.machine_not_owned'),
            'machine_id.exists' => __('maintenance.machine_not_owned'),
            'issue_type.in' => __('maintenance.issue_type_invalid'),
            'description.required' => __('maintenance.description_required'),
            'description.min' => __('maintenance.description_min'),
            'description.max' => __('maintenance.description_max'),
            'preferred_date.date_format' => __('maintenance.preferred_date_invalid'),
            'preferred_date.after_or_equal' => __('maintenance.preferred_date_invalid'),
            'preferred_date.before' => __('maintenance.preferred_date_invalid'),
            'media_file_ids.max' => __('maintenance.media_max'),
            'media_file_ids.*.exists' => __('maintenance.file_not_owned'),
            'media_file_ids.*.distinct' => __('maintenance.media_invalid'),
            'status.prohibited' => __('orders.field_forbidden'),
            'assigned_to_user_id.prohibited' => __('orders.field_forbidden'),
            'resolution_notes.prohibited' => __('orders.field_forbidden'),
            'closed_at.prohibited' => __('orders.field_forbidden'),
            'cancellation_reason.prohibited' => __('orders.field_forbidden'),
            'cancelled_by_user_id.prohibited' => __('orders.field_forbidden'),
            'user_id.prohibited' => __('orders.field_forbidden'),
            'public_id.prohibited' => __('orders.field_forbidden'),
        ];
    }
}
