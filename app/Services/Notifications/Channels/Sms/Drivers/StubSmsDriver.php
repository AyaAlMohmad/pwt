<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms\Drivers;

use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Stub SMS driver — writes messages to `storage/logs/otp.log` (channel `otp`).
 *
 * Behaviour by environment:
 *  - non-production: full message logged, including any embedded OTP.
 *  - production:     message redacted (`code=******`) per CLAUDE.md § 16.11.
 *
 * The driver also keeps an in-memory list of the most recent dispatches
 * so the test-engineer can introspect without parsing the log file.
 */
final class StubSmsDriver implements SmsDriver
{
    /**
     * @var array<int, array{to: string, message: string, context: array<string, mixed>, sent_at: string}>
     */
    private static array $sent = [];

    public function send(string $to, string $message, array $context = []): void
    {
        $isProduction = app()->environment('production');

        $loggedMessage = $isProduction
            ? self::redact($message)
            : $message;

        Log::channel('otp')->info('SMS dispatched', [
            'to' => self::maskPhone($to),
            'message' => $loggedMessage,
            'context' => $context,
            'sent_at' => Carbon::now()->toIso8601String(),
        ]);

        self::$sent[] = [
            'to' => $to,
            'message' => $message,
            'context' => $context,
            'sent_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Mask the middle of a phone number, leaving the dial code and the
     * last 2 digits visible (e.g. `+9715******67`).
     */
    private static function maskPhone(string $to): string
    {
        $length = strlen($to);
        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($to, 0, 4).str_repeat('*', $length - 6).substr($to, -2);
    }

    /**
     * Strip a 6-digit OTP from a message body (production logs only).
     */
    private static function redact(string $message): string
    {
        return (string) preg_replace('/\b\d{6}\b/', '******', $message);
    }

    /**
     * Test-introspection: every dispatch the stub has handled in this
     * process. Cleared by {@see flush()}.
     *
     * @return array<int, array{to: string, message: string, context: array<string, mixed>, sent_at: string}>
     */
    public static function dispatched(): array
    {
        return self::$sent;
    }

    /**
     * Return the most recent dispatch (or null when none).
     *
     * @return array{to: string, message: string, context: array<string, mixed>, sent_at: string}|null
     */
    public static function lastDispatched(): ?array
    {
        $count = count(self::$sent);

        return $count === 0 ? null : self::$sent[$count - 1];
    }

    public static function flush(): void
    {
        self::$sent = [];
    }
}
