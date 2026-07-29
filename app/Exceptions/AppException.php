<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Auth\AuthException;
use App\Support\Http\ApiResponse;
use RuntimeException;
use Throwable;

/**
 * Base exception for all application-domain failures that should be
 * shaped through the standard PWT error envelope by the global handler.
 *
 * Each subclass declares its `status`, `errorCode`, optional
 * field-keyed `errors` bag, and a translated `message`. The global
 * handler renders these uniformly via {@see ApiResponse::error}.
 *
 * Domain-specific bases (e.g. {@see AuthException})
 * remain valid; this class is the catch-all for new domains that don't
 * warrant their own base.
 */
abstract class AppException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?array $errors = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
