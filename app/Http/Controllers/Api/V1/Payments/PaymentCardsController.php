<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payments\StorePaymentCardRequest;
use App\Http\Requests\Api\V1\Payments\UpdatePaymentCardRequest;
use App\Http\Resources\Api\V1\Payments\PaymentCardResource;
use App\Models\PaymentCard;
use App\Models\Setting;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;
use Stripe\Stripe;

final class PaymentCardsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me/payment-cards',
        operationId: 'mePaymentCardsIndex',
        description: 'List the caller\'s saved payment cards sorted by is_default DESC then created_at DESC.',
        summary: 'List authenticated customer payment cards',
        security: [['sanctum' => []]],
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cards = $user->paymentCards()
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            PaymentCardResource::collection($cards)->resolve($request),
            __('auth.payment_cards.retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/me/payment-cards',
        operationId: 'mePaymentCardsStore',
        description: 'Save a new payment card. Setting is_default=true atomically demotes any previous default.',
        summary: 'Create a new payment card',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StorePaymentCardRequest'),
        ),
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(StorePaymentCardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        $this->configureStripe();

        try {
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = PaymentMethod::retrieve($validated['payment_method_id']);

            if ($user->stripe_customer_id !== null) {
                // Verify the customer still exists in Stripe.
                try {
                    Customer::retrieve($user->stripe_customer_id);
                } catch (ApiErrorException $e) {
                    // Customer was deleted or keys changed — recreate.
                    $user->forceFill(['stripe_customer_id' => null])->save();
                }
            }

            if ($user->stripe_customer_id === null) {
                /** @var Customer $customer */
                $customer = Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                ]);

                $user->forceFill(['stripe_customer_id' => $customer->id])->save();
            }

            $paymentMethod->attach([
                'customer' => $user->stripe_customer_id,
            ]);
        } catch (ApiErrorException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $card = $paymentMethod->card;

        $data = [
            'user_id' => $user->id,
            'stripe_payment_method_id' => $paymentMethod->id,
            'stripe_customer_id' => $user->stripe_customer_id,
            'brand' => $card?->brand ?? null,
            'last_four' => (string) ($card?->last4 ?? '0000'),
            'expiry_month' => $card !== null && $card->exp_month !== null ? str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT) : '00',
            'expiry_year' => (string) ($card?->exp_year ?? '0000'),
            'cardholder_name' => $validated['cardholder_name'] ?? ($paymentMethod->billing_details?->name ?? null),
        ];

        $card = DB::transaction(function () use ($user, $data, $validated): PaymentCard {
            $isDefault = $validated['is_default'] ?? false;

            if ($isDefault) {
                PaymentCard::query()
                    ->where('user_id', $user->id)
                    ->update(['is_default' => false]);
            }

            if (! $isDefault && PaymentCard::query()->where('user_id', $user->id)->doesntExist()) {
                $data['is_default'] = true;
            } else {
                $data['is_default'] = $isDefault;
            }

            return PaymentCard::query()->create($data);
        });

        return ApiResponse::success(
            (new PaymentCardResource($card))->resolve($request),
            __('auth.payment_cards.created'),
            201,
        );
    }

    #[OA\Get(
        path: '/api/v1/me/payment-cards/{id}',
        operationId: 'mePaymentCardsShow',
        description: 'Show a single owned payment card.',
        summary: 'Show a single payment card',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, PaymentCard $paymentCard): JsonResponse
    {
        $this->authorize('view', $paymentCard);

        return ApiResponse::success(
            (new PaymentCardResource($paymentCard))->resolve($request),
            __('auth.payment_cards.shown'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/me/payment-cards/{id}',
        operationId: 'mePaymentCardsUpdate',
        description: 'Partial update of an owned payment card. Setting is_default=true atomically demotes any previous default.',
        summary: 'Update an owned payment card',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdatePaymentCardRequest'),
        ),
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdatePaymentCardRequest $request, PaymentCard $paymentCard): JsonResponse
    {
        $this->authorize('update', $paymentCard);

        $data = $request->validated();
        $isDefault = $data['is_default'] ?? null;

        DB::transaction(function () use ($paymentCard, $data, $isDefault): void {
            if ($isDefault === true) {
                PaymentCard::query()
                    ->where('user_id', $paymentCard->user_id)
                    ->where('id', '!=', $paymentCard->id)
                    ->update(['is_default' => false]);
            }

            $paymentCard->fill($data)->save();
        });

        $paymentCard->refresh();

        return ApiResponse::success(
            (new PaymentCardResource($paymentCard))->resolve($request),
            __('auth.payment_cards.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/me/payment-cards/{id}',
        operationId: 'mePaymentCardsDestroy',
        description: 'Soft-delete an owned payment card. If it was the default, the next-most-recent surviving card is auto-promoted.',
        summary: 'Delete an owned payment card',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(PaymentCard $paymentCard): JsonResponse
    {
        $this->authorize('delete', $paymentCard);

        DB::transaction(function () use ($paymentCard): void {
            $wasDefault = $paymentCard->is_default;
            $userId = $paymentCard->user_id;

            $paymentCard->delete();

            if ($wasDefault) {
                $next = PaymentCard::query()
                    ->where('user_id', $userId)
                    ->orderByDesc('created_at')
                    ->first();

                if ($next !== null) {
                    $next->forceFill(['is_default' => true])->save();
                }
            }
        });

        return ApiResponse::success([
            'id' => $paymentCard->id,
            'deleted_at' => $paymentCard->deleted_at?->toIso8601String(),
        ], __('auth.payment_cards.deleted'));
    }

    #[OA\Post(
        path: '/api/v1/me/payment-cards/{id}/default',
        operationId: 'mePaymentCardsSetDefault',
        description: 'Set an owned payment card as the default. Atomically demotes any previous default.',
        summary: 'Set a payment card as default',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1)),
        ],
        tags: ['Payment Cards'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentCardResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function setDefault(Request $request, PaymentCard $paymentCard): JsonResponse
    {
        $this->authorize('update', $paymentCard);

        DB::transaction(function () use ($paymentCard): void {
            PaymentCard::query()
                ->where('user_id', $paymentCard->user_id)
                ->where('id', '!=', $paymentCard->id)
                ->update(['is_default' => false]);

            $paymentCard->forceFill(['is_default' => true])->save();
        });

        $paymentCard->refresh();

        return ApiResponse::success(
            (new PaymentCardResource($paymentCard))->resolve($request),
            __('auth.payment_cards.default_set'),
        );
    }

    private function configureStripe(): void
    {
        $secretKey = (string) Setting::get(
            'payment.stripe.secret_key',
            config('payment.stripe.secret_key', '')
        );

        Stripe::setApiKey($secretKey);
    }
}
