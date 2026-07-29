<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for POST /api/v1/admin/orders/{id}/notes.
 *
 * Standalone admin-internal note. Wider 2000-char cap than transition
 * notes (1000) because these rows have no transition context to share
 * the rationale across — see docs/architecture/admin-orders.md § 3.5.
 *
 * The service rejects writes on terminal orders with 409
 * ERR_INVALID_ORDER_STATE.
 */
final class AddOrderNoteRequest extends FormRequest
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
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => __('admin.orders.note_required'),
            'note.min' => __('admin.orders.note_min'),
            'note.max' => __('admin.orders.note_max_standalone'),
        ];
    }
}
