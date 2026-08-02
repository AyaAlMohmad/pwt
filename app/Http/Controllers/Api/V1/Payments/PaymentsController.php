<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyPaymentWebhookSignature;
use App\Http\Requests\Api\V1\Payments\InitiatePaymentRequest;
use App\Http\Resources\Api\V1\Payments\PaymentInitiateResponseResource;
use App\Http\Resources\Api\V1\Payments\PaymentSessionResponseResource;
use App\Http\Resources\Api\V1\Payments\PaymentStatusResponseResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Payments controller (initiate + webhook + status).
 *
 * Each method is kept ≤ 30 LOC per CLAUDE.md § 15 — validation lives in
 * Form Requests, signature verification in middleware, money + state in
 * {@see PaymentService}. The webhook intentionally has no Sanctum guard:
 * its auth surface is the HMAC signature middleware
 * ({@see VerifyPaymentWebhookSignature}).
 */
final class PaymentsController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    #[OA\Post(
        path: '/api/v1/payments/initiate',
        operationId: 'paymentsInitiate',
        description: 'Initiate a payment for an owned, payable order. For online methods (card / apple_pay / google_pay) the server creates an embedded-checkout session (SDK token) and returns session_id, payment_reference, and payment_token; the customer completes payment on-site via PayFort Merchant Page 2.0 and the gateway fires the webhook. For COD the server creates a Payment row in `captured`, auto-confirms the order, and returns the resulting state. Idempotent via the optional Idempotency-Key header (24h replay window). Rate-limited at 5 requests/minute per user+IP.',
        summary: 'Initiate payment',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/InitiatePaymentRequest'),
        ),
        tags: ['Payments'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 80, pattern: '^[A-Za-z0-9_\-]+$')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Idempotent replay — original payment returned',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentInitiateResponse')),
            new OA\Response(response: 201, description: 'Payment initiated',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentInitiateResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Payment already initiated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed / order not payable',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Order $order */
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));

        $method = PaymentMethod::from((string) $request->validated('payment_method'));
        $idempotencyKey = $this->resolveIdempotencyKey($request);
        $mode = $request->validated('mode', 'checkout');

        // If mode is payment_intent, use createSession instead of initiate
        if ($mode === 'payment_intent') {
            return $this->handlePaymentIntentMode($request, $user, $order, $method, $idempotencyKey);
        }

        [$payment, $replayed, $result] = $this->payments->initiate($user, $order, $method, $idempotencyKey);
        $payment->load('order');

        $message = $this->initiateMessage($method, $replayed);

        $resource = new PaymentInitiateResponseResource($payment);
        if ($result !== null) {
            $resource->session = [
                'session_id' => $result->sessionId,
                'payment_reference' => $result->paymentReference,
                'payment_token' => $result->paymentToken,
                'client_secret' => $result->clientSecret,
            ];
        }

        return ApiResponse::success(
            $resource->resolve($request),
            $message,
            $replayed ? 200 : 201,
        );
    }

    private function handlePaymentIntentMode(
        InitiatePaymentRequest $request,
        User $user,
        Order $order,
        PaymentMethod $method,
        ?string $idempotencyKey,
    ): JsonResponse {
        [$payment, $session] = $this->payments->createSession($user, $order, $method, $idempotencyKey);
        $payment->load('order');

        $replayed = ! $payment->wasRecentlyCreated;

        $resource = new PaymentInitiateResponseResource($payment);
        $resource->session = [
            'session_id' => $session->sessionId,
            'payment_reference' => $session->paymentReference,
            'payment_token' => $session->paymentToken,
            'client_secret' => $session->paymentToken,
        ];

        return ApiResponse::success(
            $resource->resolve($request),
            $replayed ? __('payments.replay_session') : __('payments.session_created'),
            $replayed ? 200 : 201,
        );
    }

    #[OA\Post(
        path: '/api/v1/payments/create-session',
        operationId: 'paymentsCreateSession',
        description: 'Create an embedded-checkout session for an owned, payable order. Returns a session_id (SDK token), payment_reference (merchant_reference), and the canonical payment snippet. The frontend uses these values to initialise PayFort Merchant Page 2.0 (or equivalent) and complete payment without leaving the site. Idempotent via the optional Idempotency-Key header (24h replay window). Rate-limited at 5 requests/minute per user+IP.',
        summary: 'Create payment session',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/InitiatePaymentRequest'),
        ),
        tags: ['Payments'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false,
                schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 80, pattern: '^[A-Za-z0-9_\-]+$')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Idempotent replay',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentSessionResponse')),
            new OA\Response(response: 201, description: 'Session created',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentSessionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Payment already initiated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed / order not payable',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function createSession(InitiatePaymentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Order $order */
        $order = Order::query()->findOrFail((int) $request->validated('order_id'));

        $method = PaymentMethod::from((string) $request->validated('payment_method'));
        $idempotencyKey = $this->resolveIdempotencyKey($request);

        [$payment, $session] = $this->payments->createSession($user, $order, $method, $idempotencyKey);
        $payment->load('order');

        $replayed = ! $payment->wasRecentlyCreated;

        $resource = new PaymentSessionResponseResource($payment);
        $resource->session = [
            'session_id' => $session->sessionId,
            'payment_reference' => $session->paymentReference,
            'payment_token' => $session->paymentToken,
            'amount' => (string) $order->total_amount,
            'currency' => $order->currency,
        ];

        return ApiResponse::success(
            $resource->resolve($request),
            $replayed ? __('payments.replay_session') : __('payments.session_created'),
            $replayed ? 200 : 201,
        );
    }

    #[OA\Post(
        path: '/api/v1/payments/webhook',
        operationId: 'paymentsWebhook',
        description: 'Gateway → backend callback. Auth is the HMAC signature verified by middleware (X-Stub-Signature for stub; X-Payfort-Signature for PayFort). Idempotent on gateway_transaction_id — duplicate deliveries record a payment_events row but do not re-mutate Payment or Order. Always 200 on a valid signature, even for replays, so the gateway stops retrying.',
        summary: 'Payment gateway webhook',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PaymentWebhookRequest'),
        ),
        tags: ['Payments'],
        responses: [
            new OA\Response(response: 200, description: 'Accepted (or idempotent replay)',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentWebhookResponse')),
            new OA\Response(response: 401, description: 'Invalid signature',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        /** @var array<string, mixed> $headersAll */
        $headersAll = $request->headers->all();
        $headers = [];
        foreach ($headersAll as $key => $value) {
            $headers[strtolower((string) $key)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        $this->payments->handleWebhook($rawBody, $headers);

        return ApiResponse::success(null, __('payments.webhook_accepted'), 200);
    }

    #[OA\Get(
        path: '/api/v1/payments/{id}/status',
        operationId: 'paymentsStatus',
        description: 'Returns the status snapshot for an owned Payment. Never exposes gateway_transaction_id, gateway_reference, or the checkout URL — those are not safe to leak to a potentially-compromised customer device.',
        summary: 'Payment status',
        security: [['sanctum' => []]],
        tags: ['Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 401)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/PaymentStatusResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function status(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payment = $this->payments->findOwned($id, $user);

        return ApiResponse::success(
            (new PaymentStatusResponseResource($payment))->resolve($request),
            __('payments.status_retrieved'),
        );
    }

    private function initiateMessage(PaymentMethod $method, bool $replayed): string
    {
        if ($method === PaymentMethod::Cod) {
            return $replayed ? __('payments.replay_cod') : __('payments.cod_confirmed');
        }

        return $replayed ? __('payments.replay_initiated') : __('payments.initiated');
    }

    /**
     * Pull the Idempotency-Key header and validate its shape. Mirrors
     * the Orders controller helper so both modules behave identically.
     */
    private function resolveIdempotencyKey(Request $request): ?string
    {
        $raw = $request->header('Idempotency-Key');
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $raw) !== 1) {
            abort(response()->json([
                'success' => false,
                'message' => __('orders.idempotency_key_invalid'),
                'errors' => ['Idempotency-Key' => [__('orders.idempotency_key_invalid')]],
                'code' => 'ERR_VALIDATION',
            ], 422));
        }

        return $raw;
    }
}
