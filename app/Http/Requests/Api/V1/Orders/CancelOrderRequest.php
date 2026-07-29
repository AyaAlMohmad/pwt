<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Orders;

use App\Policies\OrderPolicy;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/orders/{order}/cancel — body validation.
 *
 * Ownership check runs via {@see OrderPolicy::cancel}
 * invoked from the controller. Transition validity (current status is
 * cancellable) runs inside the service and surfaces as 409.
 *
 * See docs/architecture/orders.md § 3.4.
 */
#[OA\RequestBody(
    request: 'CancelOrderRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/CancelOrderRequest'),
)]
final class CancelOrderRequest extends FormRequest
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
            'cancellation_reason.required' => __('orders.cancel_reason_required'),
            'cancellation_reason.min' => __('orders.cancel_reason_min'),
            'cancellation_reason.max' => __('orders.cancel_reason_max'),
        ];
    }
}
