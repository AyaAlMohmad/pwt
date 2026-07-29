<?php

declare(strict_types=1);

namespace App\Exceptions\Payments;

use App\Exceptions\AppException;
use App\Http\Middleware\VerifyPaymentWebhookSignature;

/**
 * Raised by {@see VerifyPaymentWebhookSignature}
 * when the active driver rejects the inbound signature. Mapped to
 * HTTP 401 `ERR_SIGNATURE_INVALID`.
 *
 * Per CLAUDE.md § 16 rule #9 the webhook MUST refuse unsigned bodies
 * before any DB write.
 */
final class WebhookSignatureInvalidException extends AppException
{
    public static function make(): self
    {
        return new self(
            __('payments.signature_invalid'),
            401,
            'ERR_SIGNATURE_INVALID',
        );
    }
}
