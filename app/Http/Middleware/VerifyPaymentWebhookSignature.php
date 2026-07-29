<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Payments\PaymentGateway;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the inbound webhook HMAC signature against the active
 * gateway driver BEFORE the body is parsed. Per CLAUDE.md § 16 rule
 * #9 the webhook MUST reject unsigned bodies.
 *
 * The middleware passes the RAW request body (`$request->getContent()`)
 * so the signature computation matches the bytes the gateway actually
 * signed — `$request->all()` re-encoded would drift.
 *
 * Aliased as `webhook.signature` in `bootstrap/app.php`.
 *
 * See docs/architecture/payments.md § 7.5.
 */
final class VerifyPaymentWebhookSignature
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rawBody = $request->getContent();

        // Build a lower-cased header map for driver-agnostic lookup.
        /** @var array<string, mixed> $headersAll */
        $headersAll = $request->headers->all();
        $headers = [];
        foreach ($headersAll as $key => $value) {
            $headers[strtolower((string) $key)] = is_array($value)
                ? (string) ($value[0] ?? '')
                : (string) $value;
        }

        if (! $this->gateway->verifyWebhookSignature($rawBody, $headers)) {
            return ApiResponse::error(
                __('payments.signature_invalid'),
                401,
                null,
                'ERR_SIGNATURE_INVALID',
            );
        }

        return $next($request);
    }
}
