<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\Payments\PaymentCaptured;
use App\Events\Payments\PaymentFailed;
use App\Exceptions\Payments\OrderNotPayableException;
use App\Exceptions\Payments\PaymentAlreadyInitiatedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCard;
use App\Models\PaymentEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderStatusTransitionService;
use Stripe\Customer;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Stripe;
use App\Services\Payments\Dtos\PaymentSessionResult;
use App\Services\Payments\Dtos\WebhookResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestration layer for the customer-facing payment surface.
 *
 * Thin controllers (≤ 30 LOC) delegate everything to this service.
 * All state mutations are wrapped in `DB::transaction` so a failure
 * mid-flow (e.g. `OrderStatusTransitionService::confirm` throwing on a
 * promo race) rolls back the Payment row + its event so the gateway's
 * subsequent retry sees a clean slate.
 *
 * See docs/architecture/payments.md § 9.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderStatusTransitionService $transitions,
    ) {}

    /**
     * Initiate a payment for an owned, payable order.
     *
     * Returns a tuple [$payment, $replayed, $result]. The controller
     * decides 200 (replay) vs 201 (new) based on `$payment->wasRecentlyCreated`.
     * For online methods `$result` is the PaymentInitiationResult containing
     * session data (sessionId, paymentReference, etc.). For COD it is null.
     */
    public function initiate(
        User $user,
        Order $order,
        PaymentMethod $method,
        ?string $idempotencyKey,
        array $context = [],
    ): array {
        $this->assertOwnedAndPayable($user, $order, $method);

        // Idempotency replay — same user + same key within 24h.
        // For online methods we re-call the gateway so the client gets a
        // fresh session token (they may have expired).
        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotent($user, $idempotencyKey);
            if ($existing !== null) {
                $result = $method === PaymentMethod::Cod
                    ? null
                    : $this->gateway->initiate($existing, $order);

                return [$existing, true, $result];
            }
        }

        // Re-initiation guard — only `initiated` payments inside the TTL
        // block. Failed / cancelled payments DO allow a new attempt.
        $this->assertNoActiveInitiated($order);

        if ($method === PaymentMethod::Cod) {
            $payment = $this->createCodPayment($user, $order, $idempotencyKey);

            return [$payment, false, null];
        }

        [$payment, $result] = $this->createOnlinePayment($user, $order, $method, $idempotencyKey, $context);

        return [$payment, false, $result];
    }

    /**
     * Create an embedded-checkout session for an owned, payable order.
     *
     * Returns a tuple [Payment, PaymentSessionResult].  The controller
     * decides 200 (replay) vs 201 (new) based on `$payment->wasRecentlyCreated`.
     */
    public function createSession(
        User $user,
        Order $order,
        PaymentMethod $method,
        ?string $idempotencyKey,
        array $context = [],
    ): array {
        $this->assertOwnedAndPayable($user, $order, $method);

        // Idempotency replay.
        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotent($user, $idempotencyKey);
            if ($existing !== null) {
                $session = $this->gateway->createSession($existing, $order, $context);

                return [$existing, $session];
            }
        }

        $this->assertNoActiveInitiated($order);

        $tokenContext = $this->resolvePaymentMethodContext($user, $method, $context);

        $payment = DB::transaction(function () use ($user, $order, $method, $idempotencyKey, $tokenContext): Payment {
            $payment = Payment::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'payment_card_id' => $tokenContext['payment_card_id'],
                'user_id' => $user->id,
                'payment_method' => $method->value,
                'gateway' => $this->gateway->name(),
                'gateway_transaction_id' => null,
                'amount' => (string) $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentStatus::Initiated->value,
                'client_payment_url' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'type' => 'system',
                'source' => $this->gateway->name(),
                'gateway_event_id' => null,
                'payload' => [
                    'phase' => 'create-session',
                    'gateway' => $this->gateway->name(),
                ],
                'signature_valid' => null,
                'processed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            Log::info('payment.session_created', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'gateway' => $this->gateway->name(),
            ]);

            return $payment->refresh();
        });

        $session = $this->gateway->createSession($payment, $order, $tokenContext['gateway_context']);

        // Persist the merchant reference returned by the driver.
        $fields = [
            'gateway_reference' => $session->paymentReference,
        ];

        if ($session->gatewayTransactionId !== null) {
            $fields['gateway_transaction_id'] = $session->gatewayTransactionId;
        }

        if ($session->clientPaymentUrl !== null) {
            $fields['client_payment_url'] = $session->clientPaymentUrl;
        }

        $payment->forceFill($fields)->save();

        return [$payment, $session];
    }

    /**
     * Process an inbound (already-signature-verified) webhook.
     *
     * Idempotent on `gateway_transaction_id`: duplicate deliveries write
     * a PaymentEvent row but skip the Payment + Order mutations.
     *
     * @param  array<string, string>  $headers  Already lower-cased header map (currently unused; reserved for future driver-specific dispatch).
     */
    public function handleWebhook(string $rawBody, array $headers): PaymentEvent
    {
        unset($headers);
        $payload = $this->decodeBody($rawBody);
        $result = $this->gateway->parseWebhookPayload($payload);

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('gateway_transaction_id', $result->gatewayTransactionId)
            ->first();

        if ($payment === null) {
            // Orphan event — signed webhook referencing an unknown
            // transaction id. Record for admin forensics (Phase 17) and
            // return 200 so the gateway stops retrying.
            return PaymentEvent::create([
                'payment_id' => null,
                'type' => 'webhook',
                'source' => $this->gateway->name(),
                'gateway_event_id' => $result->eventId,
                'payload' => array_merge($payload, ['_orphan' => true]),
                'signature_valid' => true,
                'processed_at' => null,
                'created_at' => Carbon::now(),
            ]);
        }

        return DB::transaction(function () use ($payment, $result, $payload): PaymentEvent {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: terminal Payment → record event, do nothing
            // else. The gateway sees 200 and stops retrying.
            if ($locked->status->isTerminal()) {
                return PaymentEvent::create([
                    'payment_id' => $locked->id,
                    'type' => 'webhook',
                    'source' => $this->gateway->name(),
                    'gateway_event_id' => $result->eventId,
                    'payload' => array_merge($payload, ['_replay' => true]),
                    'signature_valid' => true,
                    'processed_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                ]);
            }

            $this->applyWebhookTransition($locked, $result);

            return PaymentEvent::create([
                'payment_id' => $locked->id,
                'type' => 'webhook',
                'source' => $this->gateway->name(),
                'gateway_event_id' => $result->eventId,
                'payload' => $payload,
                'signature_valid' => true,
                'processed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Owned-Payment fetch with policy gate. ModelNotFoundException → 404,
     * AuthorizationException → 403 (per global handler shaping).
     */
    public function findOwned(int $id, User $user): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($id);

        if ($payment->user_id !== $user->id) {
            throw new AuthorizationException(__('payments.forbidden'));
        }

        return $payment;
    }

    /**
     * Defence-in-depth: validator already enforced ownership + state,
     * but the service re-checks so the path is safe if called from a
     * future code path that bypasses the FormRequest.
     */
    private function assertOwnedAndPayable(User $user, Order $order, PaymentMethod $method): void
    {
        if ($order->user_id !== $user->id) {
            throw new AuthorizationException(__('payments.forbidden'));
        }

        if ($order->status->value !== OrderStatus::Pending->value) {
            throw OrderNotPayableException::from($order->status);
        }

        if ($order->payment_method->value !== $method->value) {
            throw OrderNotPayableException::from($order->status);
        }
    }

    private function assertNoActiveInitiated(Order $order): void
    {
        $ttl = (int) config('payment.initiated_ttl_minutes', 30);

        $exists = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentStatus::Initiated->value)
            ->where('created_at', '>=', Carbon::now()->subMinutes($ttl))
            ->exists();

        if ($exists) {
            throw PaymentAlreadyInitiatedException::make();
        }
    }

    private function findIdempotent(User $user, string $key): ?Payment
    {
        $hours = (int) config('payment.idempotency_window_hours', 24);

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->latest('id')
            ->first();

        if ($payment !== null) {
            // Force wasRecentlyCreated=false so the controller emits 200,
            // not 201, on idempotent replay.
            $payment->wasRecentlyCreated = false;
        }

        return $payment;
    }

    private function createCodPayment(User $user, Order $order, ?string $idempotencyKey): Payment
    {
        return DB::transaction(function () use ($user, $order, $idempotencyKey): Payment {
            $payment = Payment::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'user_id' => $user->id,
                'payment_method' => PaymentMethod::Cod->value,
                'gateway' => 'cod',
                'gateway_transaction_id' => 'cod_'.bin2hex(random_bytes(8)),
                'amount' => (string) $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentStatus::Captured->value,
                'client_payment_url' => null,
                'idempotency_key' => $idempotencyKey,
                'captured_at' => Carbon::now(),
            ]);

            // Transition pending → confirmed within the same transaction.
            $this->transitions->transition(
                $order,
                OrderStatus::Confirmed,
                OrderActorType::System,
                null,
                'COD initiated by customer',
            );

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'type' => 'manual',
                'source' => 'customer',
                'gateway_event_id' => null,
                'payload' => ['method' => 'cod', 'order_id' => $order->id],
                'signature_valid' => null,
                'processed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            Log::info('payment.cod_initiated', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
            ]);

            // Phase 11 — COD auto-captures, so fire the captured
            // notification after the parent transaction commits. The
            // OrderConfirmed event already fired from the transition
            // service above; the dispatcher will dedupe the second
            // order.* notification within the 30s window.
            $paymentId = (int) $payment->id;
            $orderId = (int) $order->id;
            $userId = (int) $user->id;
            DB::afterCommit(static function () use ($paymentId, $orderId, $userId): void {
                event(new PaymentCaptured($paymentId, $orderId, $userId));
            });

            return $payment;
        });
    }

    private function resolvePaymentMethodContext(
        User $user,
        PaymentMethod $method,
        array $context,
    ): array {
        if ($method !== PaymentMethod::Card) {
            return [
                'payment_card_id' => null,
                'payment_method_id' => null,
                'customer_id' => null,
                'gateway_context' => [],
            ];
        }

        if (isset($context['payment_card_id'])) {
            /** @var PaymentCard|null $card */
            $card = PaymentCard::query()
                ->where('id', (int) $context['payment_card_id'])
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->first();

            if ($card === null) {
                throw new \RuntimeException('Selected payment card not found.');
            }

            $paymentMethodId = $card->stripe_payment_method_id;

            if ($paymentMethodId === null || $paymentMethodId === '') {
                throw new \RuntimeException('Saved payment card has no Stripe payment method ID.');
            }

            if ($user->stripe_customer_id === null || $user->stripe_customer_id === '') {
                throw new \RuntimeException('User has no Stripe customer ID.');
            }

            return [
                'payment_card_id' => $card->id,
                'payment_method_id' => $paymentMethodId,
                'customer_id' => $user->stripe_customer_id,
                'gateway_context' => [
                    'payment_method_id' => $paymentMethodId,
                    'customer_id' => $user->stripe_customer_id,
                ],
            ];
        }

        if (isset($context['payment_method_id'])) {
            $customerId = $this->ensureStripeCustomer($user);
            $this->configureStripe();

            /** @var StripePaymentMethod $paymentMethod */
            $paymentMethod = StripePaymentMethod::retrieve((string) $context['payment_method_id']);

            if ($paymentMethod->customer === null || $paymentMethod->customer !== $customerId) {
                $paymentMethod->attach(['customer' => $customerId]);
            }

            return [
                'payment_card_id' => null,
                'payment_method_id' => (string) $context['payment_method_id'],
                'customer_id' => $customerId,
                'gateway_context' => [
                    'payment_method_id' => (string) $context['payment_method_id'],
                    'customer_id' => $customerId,
                ],
            ];
        }

        throw new \RuntimeException('Card payment requires payment_card_id or payment_method_id.');
    }

    private function createOnlinePayment(
        User $user,
        Order $order,
        PaymentMethod $method,
        ?string $idempotencyKey,
        array $context = [],
    ): array {
        $tokenContext = $this->resolvePaymentMethodContext($user, $method, $context);

        return DB::transaction(function () use ($user, $order, $method, $idempotencyKey, $tokenContext): array {
            $payment = Payment::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'payment_card_id' => $tokenContext['payment_card_id'],
                'user_id' => $user->id,
                'payment_method' => $method->value,
                'gateway' => $this->gateway->name(),
                'gateway_transaction_id' => null,
                'amount' => (string) $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentStatus::Initiated->value,
                'client_payment_url' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $result = $this->gateway->initiate($payment, $order, $tokenContext['gateway_context']);

            $payment->forceFill([
                'gateway_transaction_id' => $result->gatewayTransactionId,
                'gateway_reference' => $result->gatewayReference,
                'client_payment_url' => $result->clientPaymentUrl,
            ])->save();

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'type' => 'system',
                'source' => $this->gateway->name(),
                'gateway_event_id' => null,
                'payload' => [
                    'phase' => 'initiate',
                    'gateway' => $this->gateway->name(),
                    'raw' => $result->raw,
                ],
                'signature_valid' => null,
                'processed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            Log::info('payment.initiated', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'gateway' => $this->gateway->name(),
            ]);

            return [$payment->refresh(), $result];
        });
    }

    private function applyWebhookTransition(Payment $payment, WebhookResult $result): void
    {
        $now = Carbon::now();
        $target = $result->newStatus;

        switch ($target) {
            case PaymentStatus::Captured:
                $payment->forceFill([
                    'status' => PaymentStatus::Captured->value,
                    'captured_at' => $now,
                    'gateway_reference' => $payment->gateway_reference ?? $result->gatewayReference,
                ])->save();

                // Phase 11 — fire PaymentCaptured after commit.
                $paymentId = (int) $payment->id;
                $orderId = (int) $payment->order_id;
                $userId = (int) $payment->user_id;
                DB::afterCommit(static function () use ($paymentId, $orderId, $userId): void {
                    event(new PaymentCaptured($paymentId, $orderId, $userId));
                });
                break;

            case PaymentStatus::Failed:
                $payment->forceFill([
                    'status' => PaymentStatus::Failed->value,
                    'failed_at' => $now,
                    'failure_reason' => $result->failureReason !== null
                        ? Str::limit((string) $result->failureReason, 480, '')
                        : null,
                ])->save();

                Log::warning('payment.failed', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id,
                    'user_id' => $payment->user_id,
                    'gateway' => $payment->gateway,
                    'gateway_transaction_id' => $payment->gateway_transaction_id,
                    'gateway_reference' => $payment->gateway_reference,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'failure_reason' => $payment->failure_reason,
                ]);

                // Phase 11 — fire PaymentFailed after commit.
                $paymentId = (int) $payment->id;
                $orderId = (int) $payment->order_id;
                $userId = (int) $payment->user_id;
                $reason = $result->failureReason;
                DB::afterCommit(static function () use ($paymentId, $orderId, $userId, $reason): void {
                    event(new PaymentFailed($paymentId, $orderId, $userId, $reason));
                });
                break;

            case PaymentStatus::Authorized:
                $payment->forceFill([
                    'status' => PaymentStatus::Authorized->value,
                    'authorized_at' => $now,
                ])->save();
                break;

            case PaymentStatus::Cancelled:
                $payment->forceFill([
                    'status' => PaymentStatus::Cancelled->value,
                    'cancelled_at' => $now,
                ])->save();
                break;

            default:
                // No-op for refunded / initiated targets received from a
                // gateway — out of scope for MVP.
                break;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        // Form-encoded fallback for legacy PayFort callbacks.
        $parsed = [];
        parse_str($rawBody, $parsed);

        $normalised = [];
        foreach ($parsed as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    private function configureStripe(): void
    {
        $secretKey = (string) Setting::get(
            'payment.stripe.secret_key',
            config('payment.stripe.secret_key', '')
        );

        Stripe::setApiKey($secretKey);
    }

    private function ensureStripeCustomer(User $user): string
    {
        $this->configureStripe();

        if ($user->stripe_customer_id !== null && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
        }

        /** @var Customer $customer */
        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->name,
        ]);

        $user->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }
}
