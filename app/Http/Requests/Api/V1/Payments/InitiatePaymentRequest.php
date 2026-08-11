<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Payments;

use App\Enums\PaymentMethod;
use App\Models\User;
use App\Rules\OwnedOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * POST /api/v1/payments/initiate — body validation.
 *
 * Server-recomputed (never accepted from client):
 *   - amount, currency: copied from the linked order at initiate time.
 *   - gateway, gateway_transaction_id, status: server-managed.
 *
 * For card payments the client may supply a saved payment_card_id or a
 * raw Stripe payment_method_id. Raw PAN/CVV are never accepted.
 *
 * The Idempotency-Key header is parsed by the controller; this Form
 * Request only validates the body.
 *
 * See docs/architecture/payments.md § 3.1.
 */
#[OA\RequestBody(
    request: 'InitiatePaymentRequest',
    required: true,
    content: new OA\JsonContent(ref: '#/components/schemas/InitiatePaymentRequest'),
)]
final class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('payment_method'))) {
            $this->merge([
                'payment_method' => strtolower(trim((string) $this->input('payment_method'))),
            ]);
        }

        if (is_string($this->input('mode'))) {
            $this->merge([
                'mode' => strtolower(trim((string) $this->input('mode'))),
            ]);
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
            'order_id' => [
                'required',
                'integer',
                'min:1',
                'exists:orders,id,deleted_at,NULL',
                new OwnedOrder($userIdInt),
            ],
            'payment_method' => [
                'required',
                'string',
                'in:'.implode(',', PaymentMethod::values()),
            ],
            'payment_card_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('payment_cards', 'id')
                    ->where('user_id', $userIdInt)
                    ->whereNull('deleted_at'),
            ],
            'payment_method_id' => [
                'nullable',
                'string',
                'regex:/^pm_[A-Za-z0-9]+$/',
                'max:255',
            ],
            'mode' => [
                'nullable',
                'string',
                'in:checkout,payment_intent',
            ],

            // Forbidden — server-managed.
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'gateway' => ['prohibited'],
            'gateway_transaction_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order_id.required' => __('payments.order_not_owned'),
            'order_id.exists' => __('payments.order_not_owned'),
            'payment_method.in' => __('payments.method_mismatch'),
            'amount.prohibited' => __('orders.field_forbidden'),
            'currency.prohibited' => __('orders.field_forbidden'),
            'gateway.prohibited' => __('orders.field_forbidden'),
            'gateway_transaction_id.prohibited' => __('orders.field_forbidden'),
            'status.prohibited' => __('orders.field_forbidden'),
            'payment_card_id.exists' => __('payments.card_not_found'),
            'payment_method_id.regex' => __('payments.payment_method_id_invalid'),
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            if ((string) $this->input('payment_method') !== 'card') {
                return;
            }

            $cardId = $this->input('payment_card_id');
            $pmId = $this->input('payment_method_id');

            if ($cardId === null && $pmId === null) {
                $v->errors()->add('payment_card_id', __('payments.payment_card_or_method_required'));
            }
        });
    }
}
