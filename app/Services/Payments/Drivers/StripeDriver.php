<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\Dtos\PaymentInitiationResult;
use App\Services\Payments\Dtos\PaymentSessionResult;
use App\Services\Payments\Dtos\WebhookResult;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Stripe gateway driver.
 *
 * initiate() creates a hosted Checkout Session (payment_url) and also
 * fetches its linked PaymentIntent so the client can choose to use the
 * redirect URL or the client_secret for an embedded flow.
 *
 * createSession() is reserved for a direct PaymentIntent (embedded) flow.
 *
 * Webhooks verified with Stripe-Signature header using the endpoint secret.
 *
 * @see docs/architecture/payments.md § 8.3
 */
final class StripeDriver implements PaymentGateway
{
    private const NAME = 'stripe';

    public function __construct()
    {
        $secretKey = (string) $this->setting('secret_key', '');
        if ($secretKey !== '') {
            Stripe::setApiKey($secretKey);
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Create a hosted Stripe Checkout Session for the payment_url, then
     * retrieve its PaymentIntent so the client_secret is also available.
     */
    public function initiate(Payment $payment, Order $order, array $context = []): PaymentInitiationResult
    {
        $this->ensureConfigured();

        $amountCents = $this->toCents($payment->amount, $payment->currency);
        $currency = strtolower($payment->currency);
        $lineItems = $this->buildLineItems($order, $amountCents, $currency);
        $customerId = (string) ($context['customer_id'] ?? '');

        $sessionData = [
            'mode' => 'payment',
            'client_reference_id' => $payment->public_id,
            'customer_email' => $order->customer_email ?? $order->user->email ?? null,
            'line_items' => $lineItems,
            'success_url' => (string) $this->setting('return_url', ''),
            'cancel_url' => (string) $this->setting('cancel_url', ''),
            'metadata' => [
                'payment_public_id' => $payment->public_id,
                'order_id' => $order->id,
                'order_public_id' => $order->public_id,
                'tax_rate' => $order->tax_rate !== null ? (string) $order->tax_rate : '',
                'subtotal_amount' => $order->subtotal_amount !== null ? (string) $order->subtotal_amount : '',
                'total_amount' => $order->total_amount !== null ? (string) $order->total_amount : '',
                'shipping_amount' => $order->shipping_amount !== null ? (string) $order->shipping_amount : '',
            ],
        ];

        if ($customerId !== '') {
            $sessionData['customer'] = $customerId;
        }

        /** @var Session $session */
        $session = Session::create($sessionData);

        $paymentIntentId = (string) ($session->payment_intent ?? '');

        /** @var PaymentIntent $intent */
        $intent = $paymentIntentId !== '' ? PaymentIntent::retrieve($paymentIntentId) : null;

        return new PaymentInitiationResult(
            clientPaymentUrl: (string) $session->url,
            gatewayTransactionId: $paymentIntentId !== '' ? $paymentIntentId : (string) $session->id,
            gatewayReference: $intent !== null ? (string) $intent->client_secret : '',
            expiresAt: $session->expires_at !== null
                ? CarbonImmutable::createFromTimestamp($session->expires_at)
                : CarbonImmutable::now()->addMinutes((int) config('payment.initiated_ttl_minutes', 30)),
            sessionId: (string) $session->id,
            paymentReference: $paymentIntentId !== '' ? $paymentIntentId : (string) $session->id,
            paymentToken: $intent !== null ? (string) $intent->client_secret : null,
            clientSecret: $intent !== null ? (string) $intent->client_secret : null,
            raw: $intent !== null ? $intent->toArray() : $session->toArray(),
        );
    }

    /**
     * Create a Stripe PaymentIntent for embedded checkout.
     * Returns the client_secret as the session token.
     */
    public function createSession(Payment $payment, Order $order, array $context = []): PaymentSessionResult
    {
        $this->ensureConfigured();

        $amountCents = $this->toCents($payment->amount, $payment->currency);
        $currency = strtolower($payment->currency);
        $paymentMethodId = (string) ($context['payment_method_id'] ?? '');
        $customerId = (string) ($context['customer_id'] ?? '');

        $intentData = [
            'amount' => $amountCents,
            'currency' => $currency,
            'metadata' => [
                'payment_public_id' => $payment->public_id,
                'order_id' => $order->id,
                'order_public_id' => $order->public_id,
                'tax_rate' => $order->tax_rate !== null ? (string) $order->tax_rate : '',
                'subtotal_amount' => $order->subtotal_amount !== null ? (string) $order->subtotal_amount : '',
                'total_amount' => $order->total_amount !== null ? (string) $order->total_amount : '',
                'shipping_amount' => $order->shipping_amount !== null ? (string) $order->shipping_amount : '',
            ],
        ];

        if ($paymentMethodId !== '') {
            $intentData['customer'] = $customerId;
            $intentData['payment_method'] = $paymentMethodId;
            $intentData['confirm'] = true;
            $intentData['return_url'] = (string) $this->setting('return_url', '');
        } else {
            $intentData['automatic_payment_methods'] = [
                'enabled' => true,
                'allow_redirects' => 'never',
            ];
        }

        /** @var PaymentIntent $intent */
        $intent = PaymentIntent::create($intentData);

        return new PaymentSessionResult(
            sessionId: (string) $intent->client_secret,
            paymentReference: (string) $intent->id,
            paymentToken: (string) $intent->client_secret,
            gatewayTransactionId: (string) $intent->id,
            clientPaymentUrl: null,
            raw: $intent->toArray(),
        );
    }

    /**
     * Verify the Stripe webhook signature using the endpoint secret.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $secret = (string) $this->setting('webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $signature = (string) ($headers['stripe-signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent($rawBody, $signature, $secret);

            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        }
    }

    /**
     * Parse a Stripe webhook event into a driver-agnostic WebhookResult.
     */
    public function parseWebhookPayload(array $payload): WebhookResult
    {
        $eventType = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];

        $txnId = $this->resolveTransactionId($eventType, $object);
        $gatewayReference = isset($object['payment_intent'])
            ? (string) $object['payment_intent']
            : null;

        $status = $this->mapEventToStatus($eventType, $object);

        $amount = isset($object['amount_total'])
            ? (string) ($object['amount_total'] / 100)
            : (isset($object['amount']) ? (string) ($object['amount'] / 100) : null);
        $currency = isset($object['currency'])
            ? strtoupper((string) $object['currency'])
            : null;

        $failureReason = null;
        if ($status === PaymentStatus::Failed && isset($object['last_payment_error'])) {
            $failureReason = (string) ($object['last_payment_error']['message']
                ?? $object['last_payment_error']['code']
                ?? 'Payment failed');
        }

        return new WebhookResult(
            gatewayTransactionId: $txnId,
            gatewayReference: $gatewayReference,
            newStatus: $status,
            amount: $amount,
            currency: $currency,
            failureReason: $failureReason,
            eventId: (string) ($payload['id'] ?? ''),
            raw: $payload,
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function ensureConfigured(): void
    {
        $secretKey = (string) $this->setting('secret_key', '');
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }
    }

    /**
     * Read a driver-specific setting from the database settings table,
     * falling back to config (which ultimately resolves to .env).
     */
    private function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get("payment.stripe.$key", config("payment.stripe.$key", $default));
    }

    /**
     * Convert a decimal amount string to the smallest currency unit (cents).
     *
     * Zero-decimal currencies (e.g. JPY) are not handled here; this
     * implementation assumes AED/USD-style two-decimal currencies.
     */
    private function toCents(string|float $amount, string $currency): int
    {
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        $value = (float) $amount;

        if (in_array(strtolower($currency), $zeroDecimal, true)) {
            return (int) round($value);
        }

        return (int) round($value * 100);
    }

    /**
     * Build Stripe Checkout Session line_items from order items or
     * fall back to a single summary line.
     */
    private function buildLineItems(Order $order, int $amountCents, string $currency): array
    {
        $items = [];
        if ($order->relationLoaded('items') || $order->items()->exists()) {
            foreach ($order->items as $item) {
                $items[] = [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $item->product_name ?? ('Item '.$item->id),
                        ],
                        'unit_amount' => (int) round((float) $item->unit_price * 100),
                    ],
                    'quantity' => (int) $item->quantity,
                ];
            }
        }

        if ($items === []) {
            $items = [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Order #'.($order->public_id ?? $order->id),
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => 1,
            ]];
        }

        return $items;
    }

    private function resolveTransactionId(string $eventType, array $object): string
    {
        // checkout.session.* events use the session id
        if (str_starts_with($eventType, 'checkout.session.')) {
            return (string) ($object['id'] ?? '');
        }

        // payment_intent.* events use the payment intent id
        return (string) ($object['id'] ?? '');
    }

    private function mapEventToStatus(string $eventType, array $object): PaymentStatus
    {
        return match ($eventType) {
            'checkout.session.completed' => ($object['payment_status'] ?? '') === 'paid'
                ? PaymentStatus::Captured
                : PaymentStatus::Authorized,
            'payment_intent.created' => PaymentStatus::Initiated,
            'payment_intent.requires_action' => PaymentStatus::Initiated,
            'payment_intent.succeeded' => PaymentStatus::Captured,
            'payment_intent.amount_capturable_updated' => PaymentStatus::Authorized,
            'payment_intent.payment_failed' => PaymentStatus::Failed,
            'payment_intent.canceled' => PaymentStatus::Cancelled,
            'checkout.session.expired' => PaymentStatus::Cancelled,
            default => PaymentStatus::Failed,
        };
    }
}
