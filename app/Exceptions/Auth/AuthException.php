<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;
use Throwable;

/**
 * Base exception for all customer-auth domain failures.
 *
 * Carries an HTTP status + stable error code consumed by the global
 * exception handler to shape the standard PWT error envelope.
 *
 * Optional bag of field-keyed messages (`$errors`) for cases where the
 * client UI should attach the failure to a specific input.
 */
abstract class AuthException extends RuntimeException
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
