<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms;

/**
 * Outbound SMS dispatcher.
 *
 * Implementations may queue internally; callers MUST NOT assume the
 * message is delivered synchronously after the call returns.
 */
interface SmsDriver
{
    /**
     * @param  string  $to  E.164 phone number (must start with `+`).
     * @param  string  $message  Already-localized template body.
     * @param  array<string, mixed>  $context  Optional structured metadata
     *                                         (e.g. ['purpose' => 'register']).
     */
    public function send(string $to, string $message, array $context = []): void;
}
