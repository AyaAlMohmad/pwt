<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Maintenance;

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\RequestBody(
    request: 'RescheduleMaintenanceRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/RescheduleMaintenanceRequest'),
)]
final class RescheduleMaintenanceRequest extends FormRequest
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
            'preferred_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:today',
                'before:+90 days',
            ],
            'preferred_time' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferred_date.required' => __('maintenance.preferred_date_required'),
            'preferred_date.date_format' => __('maintenance.preferred_date_invalid'),
            'preferred_date.after_or_equal' => __('maintenance.preferred_date_invalid'),
            'preferred_date.before' => __('maintenance.preferred_date_invalid'),
            'preferred_time.max' => __('maintenance.preferred_time_invalid'),
        ];
    }
}
