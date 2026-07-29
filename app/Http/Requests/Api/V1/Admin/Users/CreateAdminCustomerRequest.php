<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Enums\AccountType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body validation for POST /api/v1/admin/users (admin creates a customer).
 */
final class CreateAdminCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone_country_code' => ['required', 'string', 'max:5'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'locale' => ['nullable', 'string', 'in:en,ar'],
            'account_type' => ['required', 'string', 'in:'.implode(',', AccountType::values())],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:account_type,'.AccountType::Company->value],
            'role' => ['nullable', 'string', 'in:'.implode(',', UserRole::values())],
            'password' => ['required', 'string', 'min:8', 'max:255'],

            // Optional address to create alongside the user.
            'address' => ['nullable', 'array'],
            'address.label' => ['required_with:address', 'string', 'max:60'],
            'address.country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'address.region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'address.recipient_name' => ['nullable', 'string', 'max:120'],
            'address.recipient_phone' => ['nullable', 'string', 'max:20'],
            'address.recipient_phone_country_code' => ['nullable', 'string', 'max:6', 'regex:/^\+?\d{1,5}$/'],
            'address.line1' => ['required_with:address', 'string', 'max:200'],
            'address.line2' => ['nullable', 'string', 'max:200'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.area' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.notes' => ['nullable', 'string', 'max:1000'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
