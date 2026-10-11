<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Machines;

use App\Enums\MachineSource;
use App\Enums\MachineVoltage;
use App\Enums\ProductTerm;
use App\Models\Machine;
use App\Rules\ActiveProduct;
use App\Rules\AddressBelongsToUser;
use App\Rules\IsCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body validation for PUT /api/v1/admin/machines/{id}.
 *
 * `user_id` (ownership) and `product_id` are reassignable by admins.
 * Because `address_id` rows belong to a single user, changing `user_id`
 * REQUIRES an `address_id` owned by the new customer in the same
 * request — otherwise the machine would point at a foreign address.
 * Reassignments are written to a dedicated `admin.machine.reassigned`
 * activity row so the ownership history stays reconstructable.
 *
 * The service still independently rejects writes against soft-deleted
 * rows (409 ERR_RESOURCE_TRASHED).
 *
 * See docs/architecture/admin-machines-maintenance.md § 3.4.
 */
final class UpdateMachineRequest extends FormRequest
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
        $id = (int) $this->route('id');
        $machine = $id > 0
            ? Machine::query()->withTrashed()->find($id)
            : null;
        // Ownership can be reassigned in the same request, so the
        // address must be validated against the EFFECTIVE owner (body
        // override > current column).
        $ownerId = $this->has('user_id') && is_numeric($this->input('user_id'))
            ? (int) $this->input('user_id')
            : $machine?->user_id;

        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id', new IsCustomer],
            'product_id' => ['sometimes', 'integer', 'exists:products,id', new ActiveProduct],
            'serial_number' => [
                'sometimes', 'string', 'max:80',
                Rule::unique('machines', 'serial_number')->ignore($id),
            ],
            'term' => ['sometimes', 'string', 'in:'.implode(',', ProductTerm::values())],
            'installed_at' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'filter_change_interval_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'next_maintenance_due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'address_id' => array_merge(
                ['sometimes', 'integer', 'exists:addresses,id'],
                $ownerId !== null ? [new AddressBelongsToUser((int) $ownerId)] : [],
            ),
            'location_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'country_code' => ['sometimes', 'string', 'size:2', 'exists:countries,code'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'voltage' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', MachineVoltage::values())],
            'individual_buy_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', 'in:'.implode(',', MachineSource::values())],
            'order_id' => ['sometimes', 'nullable', 'integer', 'exists:orders,id'],
            'order_installation_id' => ['sometimes', 'nullable', 'integer', 'exists:order_installations,id'],

            // Row identity — immutable by definition.
            'id' => ['prohibited'],
            'public_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $id = (int) $this->route('id');
            $machine = $id > 0
                ? Machine::query()->withTrashed()->find($id)
                : null;

            $payload = $this->validated();

            // Reassigning ownership orphans the current address_id (it
            // belongs to the previous owner), so a replacement address
            // owned by the new customer must come in the same request.
            if (isset($payload['user_id'])
                && $machine !== null
                && (int) $payload['user_id'] !== (int) $machine->user_id
                && ! array_key_exists('address_id', $payload)
            ) {
                $v->errors()->add(
                    'address_id',
                    __('admin.machines.owner_change_requires_address'),
                );
            }

            $nextRaw = $payload['next_maintenance_due_at'] ?? null;
            $hasNextKey = $this->has('next_maintenance_due_at');
            $intervalRaw = $payload['filter_change_interval_months'] ?? null;
            $hasIntervalKey = $this->has('filter_change_interval_months');

            // Determine the effective interval after this update (body
            // override > existing column).
            $effectiveInterval = $hasIntervalKey ? $intervalRaw : $machine?->filter_change_interval_months;

            if ($hasNextKey && $nextRaw !== null && $nextRaw !== '' && ($effectiveInterval === null || $effectiveInterval === 0)) {
                $v->errors()->add(
                    'next_maintenance_due_at',
                    __('admin.machines.override_requires_interval'),
                );

                return;
            }

            if ($hasNextKey && $nextRaw !== null && $nextRaw !== '') {
                $installedAt = isset($payload['installed_at']) && is_string($payload['installed_at']) && $payload['installed_at'] !== ''
                    ? Carbon::parse($payload['installed_at'])
                    : $machine?->installed_at;

                if ($installedAt !== null) {
                    try {
                        $next = Carbon::parse((string) $nextRaw);
                    } catch (\Throwable) {
                        return;
                    }
                    if ($next->lessThan($installedAt)) {
                        $v->errors()->add(
                            'next_maintenance_due_at',
                            __('validation.after_or_equal', [
                                'attribute' => 'next_maintenance_due_at',
                                'date' => $installedAt->toDateString(),
                            ]),
                        );
                    }
                }
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
            'created_at.prohibited' => __('admin.machines.forbidden_field'),
            'updated_at.prohibited' => __('admin.machines.forbidden_field'),
            'deleted_at.prohibited' => __('admin.machines.forbidden_field'),
        ];
    }
}
