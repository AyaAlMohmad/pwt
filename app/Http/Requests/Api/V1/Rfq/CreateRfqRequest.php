<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Rfq;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/rfq — public guest RFQ form.
 */
final class CreateRfqRequest extends FormRequest
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
            'company_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_name.required' => __('rfq.company_name_required'),
            'company_name.min' => __('rfq.company_name_min'),
            'email.required' => __('rfq.email_required'),
            'email.email' => __('rfq.email_invalid'),
        ];
    }
}
