<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Dtos\PaymentInitiationResult;
use App\Services\Payments\Dtos\PaymentSessionResult;
use App\Services\Payments\Dtos\WebhookResult;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Local / testing stub gateway driver.
 *
 * `initiate()` returns a synthetic checkout URL and a `stub_*` correlator
 * id; no outbound HTTP. `verifyWebhookSignature()` checks the
 * `X-Stub-Signature` header against a constant (`stub-signature-ok` by
 * default; override via `STUB_PAYMENT_SIGNATURE` env). `parseWebhookPayload()`
 * accepts a small JSON shape mimicking PayFort's canonical fields.
 *
 * @see docs/architecture/payments.md § 8.3
 */
final class StubDriver implements PaymentGateway
{
    private const NAME = 'stub';

    public function name(): string
    {
        return self::NAME;
    }

    public function initiate(Payment $payment, Order $order, array $context = []): PaymentInitiationResult
    {
        $session = (string) Str::uuid();
        $ttl = (int) config('payment.initiated_ttl_minutes', 30);

        return new PaymentInitiationResult(
            sessionId: $session,
            paymentReference: $payment->public_id,
            paymentToken: 'stub_token_'.bin2hex(random_bytes(4)),
            gatewayTransactionId: 'stub_'.bin2hex(random_bytes(8)),
            gatewayReference: $payment->public_id,
            expiresAt: CarbonImmutable::now()->addMinutes($ttl),
            raw: [
                'driver' => self::NAME,
                'session' => $session,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
        );
    }

    public function createSession(Payment $payment, Order $order): PaymentSessionResult
    {
        return new PaymentSessionResult(
            sessionId: 'stub_session_'.bin2hex(random_bytes(8)),
            paymentReference: $payment->public_id,
            paymentToken: 'stub_token_'.bin2hex(random_bytes(4)),
            raw: [
                'driver' => self::NAME,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ],
        );
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $header = (string) config('payment.stub.signature_header', 'x-stub-signature');
        $expected = (string) config('payment.stub.signature_value', 'stub-signature-ok');

        $provided = (string) ($headers[$header] ?? '');

        if ($provided === '' || $expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public function parseWebhookPayload(array $payload): WebhookResult
    {
        $txnId = (string) ($payload['transaction_id'] ?? $payload['gateway_transaction_id'] ?? '');
        $statusRaw = strtolower((string) ($payload['status'] ?? ''));

        $status = match ($statusRaw) {
            'success', 'captured', 'payment.captured' => PaymentStatus::Captured,
            'authorized', 'payment.authorized' => PaymentStatus::Authorized,
            'failed', 'declined', 'payment.failed' => PaymentStatus::Failed,
            'cancelled', 'canceled' => PaymentStatus::Cancelled,
            default => PaymentStatus::Failed,
        };

        $amount = isset($payload['amount']) ? (string) $payload['amount'] : null;
        $currency = isset($payload['currency']) ? (string) $payload['currency'] : null;

        return new WebhookResult(
            gatewayTransactionId: $txnId,
            gatewayReference: isset($payload['reference'])
                ? (string) $payload['reference']
                : (isset($payload['merchant_reference']) ? (string) $payload['merchant_reference'] : null),
            newStatus: $status,
            amount: $amount,
            currency: $currency,
            failureReason: isset($payload['failure_reason']) ? (string) $payload['failure_reason'] : null,
            eventId: isset($payload['event_id']) ? (string) $payload['event_id'] : null,
            raw: $payload,
        );
    }
}
