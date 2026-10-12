<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenanceStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a status transition on a machine's latest open maintenance request.
 *
 * Accepts numeric status codes (1=new, 2=assigned, 3=in_progress, 4=completed,
 * 5=cancelled) or string values (case-insensitive) so the frontend can
 * send either format.
 *
 * @see \App\Http\Controllers\Api\V1\Admin\Machines\AdminMachinesController::updateMaintenanceStatus()
 */
final class UpdateMachineMaintenanceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');

        // Numeric codes are normalised to the enum value before the
        // rules run: 1=new 2=assigned 3=in_progress 4=completed 5=cancelled.
        // Unresolvable codes fall through untouched so the `in:` rule
        // surfaces a 422.
        if (is_int($status) || (is_string($status) && ctype_digit($status))) {
            $resolved = MaintenanceStatus::tryFromCode((int) $status);
            if ($resolved !== null) {
                $this->merge(['status' => $resolved->value]);
            }

            return;
        }

        if (is_string($status) && $status !== '') {
            $this->merge(['status' => strtolower($status)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:in_progress,cancelled,assigned,completed,new'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'issue_type' => ['nullable', 'string', 'in:'.implode(',', MaintenanceIssueType::values())],
            'description' => ['nullable', 'string', 'min:3', 'max:2000'],
        ];
    }
}
