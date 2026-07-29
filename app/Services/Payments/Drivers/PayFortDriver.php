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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Amazon Payment Services (PayFort) gateway driver.
 *
 * Supports both redirect (`initiate`) and embedded-checkout (`createSession`)
 * flows.  All outbound HTTP is real; credentials are validated eagerly so
 * misconfigurations surface loud `RuntimeException`s.
 *
 * Signature scheme (request):
 *   1. Sort parameters alphabetically by key.
 *   2. Concatenate `key1=value1key2=value2...`.
 *   3. Wrap with `sha_request_phrase` prefix + suffix.
 *   4. SHA-256.
 *
 * Signature scheme (response / webhook):
 *   Same but with `sha_response_phrase` and EXCLUDING the `signature` key.
 *
 * @see docs/architecture/payments.md § 8.3
 */
final class PayFortDriver implements PaymentGateway
{
    private const NAME = 'payfort';

    public function name(): string
    {
        return self::NAME;
    }

    public function initiate(Payment $payment, Order $order, array $context = []): PaymentInitiationResult
    {
        $this->assertCredentials();

        // Step 1 — obtain an SDK token for the embedded-checkout session.
        $tokenBody = [
            'service_command' => 'SDK_TOKEN',
            'access_code' => (string) config('payment.payfort.access_code'),
            'merchant_identifier' => (string) config('payment.payfort.merchant_identifier'),
            'language' => $this->resolveLanguage(),
        ];
        $tokenBody['signature'] = $this->signPayload($tokenBody, 'request');

        $tokenResponse = $this->postToPayFort($tokenBody);

        if (empty($tokenResponse['sdk_token'])) {
            Log::error('payfort.sdk_token_failed', [
                'payment_id' => $payment->id,
                'response' => $tokenResponse,
            ]);
            throw new RuntimeException(
                'PayFort SDK_TOKEN failed: '.($tokenResponse['response_message'] ?? 'Unknown error')
            );
        }

        return new PaymentInitiationResult(
            sessionId: (string) $tokenResponse['sdk_token'],
            paymentReference: $payment->public_id,
            paymentToken: null,
            gatewayTransactionId: (string) $tokenResponse['sdk_token'],
            gatewayReference: $payment->public_id,
            expiresAt: now()->addMinutes((int) config('payment.initiated_ttl_minutes', 30))->toImmutable(),
            raw: $tokenResponse,
        );
    }

    public function createSession(Payment $payment, Order $order): PaymentSessionResult
    {
        $this->assertCredentials();

        $body = [
            'service_command' => 'SDK_TOKEN',
            'access_code' => (string) config('payment.payfort.access_code'),
            'merchant_identifier' => (string) config('payment.payfort.merchant_identifier'),
            'language' => $this->resolveLanguage(),
        ];
        $body['signature'] = $this->signPayload($body, 'request');

        $response = $this->postToPayFort($body);

        if (empty($response['sdk_token'])) {
            Log::error('payfort.sdk_token_failed', [
                'payment_id' => $payment->id,
                'response' => $response,
            ]);
            throw new RuntimeException(
                'PayFort SDK_TOKEN failed: '.($response['response_message'] ?? 'Unknown error')
            );
        }

        return new PaymentSessionResult(
            sessionId: (string) $response['sdk_token'],
            paymentReference: $payment->public_id,
            paymentToken: null,
            raw: $response,
        );
    }

    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $this->assertCredentials();

        $payload = $this->decodeBody($rawBody);
        $provided = (string) ($payload['signature'] ?? '');

        if ($provided === '') {
            return false;
        }

        $computed = $this->signPayload($payload, 'response');

        return hash_equals($computed, $provided);
    }

    public function parseWebhookPayload(array $payload): WebhookResult
    {
        // PayFort canonical mapping.
        $statusCode = (string) ($payload['status'] ?? '');
        $responseCode = (string) ($payload['response_code'] ?? '');

        $status = match (true) {
            $statusCode === '14' && str_starts_with($responseCode, '14') => PaymentStatus::Captured,
            $statusCode === '02' => PaymentStatus::Authorized,
            $statusCode === '00' || $statusCode === '' => PaymentStatus::Failed,
            default => PaymentStatus::Failed,
        };

        return new WebhookResult(
            gatewayTransactionId: (string) ($payload['fort_id'] ?? ''),
            gatewayReference: isset($payload['merchant_reference']) ? (string) $payload['merchant_reference'] : null,
            newStatus: $status,
            amount: isset($payload['amount'])
                ? bcdiv((string) $payload['amount'], '100', 2)
                : null,
            currency: isset($payload['currency']) ? (string) $payload['currency'] : null,
            failureReason: isset($payload['response_message']) ? (string) $payload['response_message'] : null,
            eventId: isset($payload['fort_id'])
                ? ($responseCode.'-'.((string) $payload['fort_id']))
                : null,
            raw: $payload,
        );
    }

    /**
     * Ensure mandatory credentials are present; throw loud otherwise.
     */
    private function assertCredentials(): void
    {
        $accessCode = (string) config('payment.payfort.access_code');
        if ($accessCode === '') {
            throw new RuntimeException(
                'PayFort gateway requires real credentials. See config/payment.php.'
            );
        }
    }

    /**
     * Resolve the locale for PayFort ('en' or 'ar').
     */
    private function resolveLanguage(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
    }

    /**
     * POST a JSON body to the PayFort API endpoint.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postToPayFort(array $body): array
    {
        $baseUrl = (string) config('payment.payfort.base_url', 'https://sbpaymentservices.payfort.com');
        $url = rtrim($baseUrl, '/').'/FortAPI/paymentApi';

        $httpResponse = Http::timeout(30)->asJson()->post($url, $body);

        $json = $httpResponse->json();
        if (! is_array($json)) {
            Log::error('payfort.non_json_response', [
                'url' => $url,
                'status' => $httpResponse->status(),
                'body' => $httpResponse->body(),
            ]);
            throw new RuntimeException('PayFort returned a non-JSON response.');
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * Build the canonical PayFort SHA-256 signature.
     *
     * @param  array<string, mixed>  $payload
     * @param  'request'|'response'  $direction
     */
    private function signPayload(array $payload, string $direction): string
    {
        $phraseKey = $direction === 'request'
            ? 'payment.payfort.sha_request_phrase'
            : 'payment.payfort.sha_response_phrase';

        $phrase = (string) config($phraseKey, '');

        // Exclude the signature key itself from signing.
        $filtered = [];
        foreach ($payload as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $filtered[$key] = (string) $value;
        }

        // Sort alphabetically by key.
        ksort($filtered, SORT_STRING);

        $concat = '';
        foreach ($filtered as $key => $value) {
            $concat .= $key.'='.$value;
        }

        $toHash = $phrase.$concat.$phrase;

        return hash('sha256', $toHash);
    }

    /**
     * Decode an inbound webhook body (JSON or form-encoded).
     *
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

        $parsed = [];
        parse_str($rawBody, $parsed);

        $normalised = [];
        foreach ($parsed as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }
}
