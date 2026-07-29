<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms\Drivers;

use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Unifonic SMS driver — sends messages via Unifonic REST API.
 *
 * Requires driver_config:
 *   - appsid    (string) Unifonic App SID
 *   - senderid  (string) Sender ID (optional, falls back to config sms.sender)
 *   - base_url  (string) API base URL (default: https://api.unifonic.com)
 */
final class UnifonicDriver implements SmsDriver
{
    private const DEFAULT_BASE_URL = 'https://api.unifonic.com';

    public function send(string $to, string $message, array $context = []): void
    {
        $config = config('sms.driver_config.' . config('sms.active_driver_key', 'unifonic'), []);

        $appSid   = $config['appsid'] ?? null;
        $senderId = $config['senderid'] ?? config('sms.sender', 'PWT');
        $baseUrl  = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');

        if (empty($appSid)) {
            Log::warning('SMS_UNIFONIC_MISSING_CONFIG', [
                'to' => $to,
                'context' => $context,
            ]);

            throw new \RuntimeException('Unifonic configuration missing: appsid');
        }

        // Strip leading '+' if present (Unifonic expects numbers without it)
        $recipient = ltrim($to, '+');

        $url = "{$baseUrl}/rest/Messages/Send";

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->get($url, [
                'AppSid'      => $appSid,
                'Recipient'   => $recipient,
                'Body'        => $message,
                'SenderID'    => $senderId,
                'BaseEncode'  => 'false',
                'MessageType' => 'text',
            ]);

            $data = $response->json();
            $success = $response->successful() && ($data['success'] ?? false);

            if ($success) {
                Log::channel('otp')->info('SMS_UNIFONIC_SENT', [
                    'to'       => $to,
                    'messageId'=> $data['data']['MessageID'] ?? null,
                    'status'   => $data['data']['Status'] ?? null,
                    'context'  => $context,
                    'sent_at'  => Carbon::now()->toIso8601String(),
                ]);
            } else {
                $errorCode = $data['errorCode'] ?? 'unknown';
                $errorMessage = $data['message'] ?? $response->body();

                Log::channel('otp')->error('SMS_UNIFONIC_FAILED', [
                    'to'          => $to,
                    'errorCode'   => $errorCode,
                    'errorMessage'=> $errorMessage,
                    'response'    => $data,
                    'context'     => $context,
                    'sent_at'     => Carbon::now()->toIso8601String(),
                ]);

                throw new \RuntimeException("Unifonic API error [{$errorCode}]: {$errorMessage}");
            }
        } catch (\Throwable $e) {
            Log::channel('otp')->error('SMS_UNIFONIC_EXCEPTION', [
                'to'        => $to,
                'error'     => $e->getMessage(),
                'class'     => get_class($e),
                'context'   => $context,
                'sent_at'   => Carbon::now()->toIso8601String(),
            ]);

            throw $e;
        }
    }
}
