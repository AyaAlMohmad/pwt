<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use App\Enums\MachineVoltage;
use App\Enums\ProductTerm;
use App\Rules\ActiveProduct;
use App\Rules\IsCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Body validation for POST /api/v1/admin/machines.
 *
 * Server-stamped fields (`public_id`, `source = created`,
 * `order_id = null`, `order_installation_id = null`) are forbidden
 * here — see § 3.3. `next_maintenance_due_at` is permitted ONLY when
 * `filter_change_interval_months` is also set (cross-field rule).
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.3.
 */
final class CreateMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $voltage = $this->input('voltage');
        if (is_string($voltage) && $voltage !== '') {
            $this->merge(['voltage' => MachineVoltage::normalise($voltage) ?? $voltage]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = (int) ($this->input('user_id') ?? 0);

        return [
            'user_id' => ['required', 'integer', 'exists:users,id', new IsCustomer],
            'product_id' => ['required', 'integer', 'exists:products,id', new ActiveProduct],
            'serial_number' => ['required', 'string', 'max:80', 'unique:machines,serial_number'],
            'term' => ['required', 'string', 'in:'.implode(',', ProductTerm::values())],
            'installed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'filter_change_interval_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'next_maintenance_due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:installed_at'],
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'voltage' => ['nullable', 'string', 'in:'.implode(',', MachineVoltage::values())],

            // Fields removed from create API — explicitly reject them.
            'address_id' => ['prohibited'],
            'location_label' => ['prohibited'],
            'notes' => ['prohibited'],
            'individual_buy_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            // Server-managed — reject explicit attempts.
            'id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'source' => ['prohibited'],
            'order_id' => ['prohibited'],
            'order_installation_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $next = $this->input('next_maintenance_due_at');
            $interval = $this->input('filter_change_interval_months');

            if ($next !== null && $next !== '' && ($interval === null || $interval === '')) {
                $v->errors()->add(
                    'next_maintenance_due_at',
                    __('admin.machines.override_requires_interval'),
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'serial_number.unique' => __('admin.machines.duplicate_serial'),
            'country_code.exists' => __('admin.orders.country_unknown'),
            'id.prohibited' => __('admin.machines.forbidden_field'),
            'public_id.prohibited' => __('admin.machines.forbidden_field'),
            'source.prohibited' => __('admin.machines.forbidden_field'),
            'order_id.prohibited' => __('admin.machines.forbidden_field'),
            'order_installation_id.prohibited' => __('admin.machines.forbidden_field'),
            'created_at.prohibited' => __('admin.machines.forbidden_field'),
            'updated_at.prohibited' => __('admin.machines.forbidden_field'),
            'deleted_at.prohibited' => __('admin.machines.forbidden_field'),
            'address_id.prohibited' => __('admin.machines.forbidden_field'),
            'location_label.prohibited' => __('admin.machines.forbidden_field'),
            'notes.prohibited' => __('admin.machines.forbidden_field'),
        ];
    }
}
