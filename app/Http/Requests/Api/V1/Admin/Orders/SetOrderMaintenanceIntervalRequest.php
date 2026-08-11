<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use Illuminate\Foundation\Http\FormRequest;

final class SetOrderMaintenanceIntervalRequest extends FormRequest
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
            'filter_change_interval_months' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }
}
