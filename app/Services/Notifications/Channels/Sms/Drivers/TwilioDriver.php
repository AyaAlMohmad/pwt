<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms\Drivers;

use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio SMS driver — sends messages via Twilio REST API.
 *
 * Requires driver_config:
 *   - account_sid  (string) Twilio Account SID
 *   - auth_token   (string) Twilio Auth Token
 *   - from_number  (string) Twilio phone number (E.164)
 */
final class TwilioDriver implements SmsDriver
{
    public function send(string $to, string $message, array $context = []): void
    {
        $config = config('sms.driver_config.' . config('sms.active_driver_key', 'twilio'), []);

        $accountSid = $config['account_sid'] ?? null;
        $authToken  = $config['auth_token'] ?? null;
        $fromNumber = $config['from_number'] ?? null;

        if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
            Log::warning('SMS_TWILIO_MISSING_CONFIG', [
                'to' => $to,
                'context' => $context,
            ]);

            throw new \RuntimeException('Twilio configuration missing: account_sid, auth_token, or from_number');
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

        try {
            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post($url, [
                    'From' => $fromNumber,
                    'To'   => $to,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                Log::channel('otp')->info('SMS_TWILIO_SENT', [
                    'to'      => $to,
                    'sid'     => $response->json('sid'),
                    'status'  => $response->json('status'),
                    'context' => $context,
                    'sent_at' => Carbon::now()->toIso8601String(),
                ]);
            } else {
                Log::channel('otp')->error('SMS_TWILIO_FAILED', [
                    'to'       => $to,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                    'context'  => $context,
                    'sent_at'  => Carbon::now()->toIso8601String(),
                ]);

                throw new \RuntimeException('Twilio API error: ' . $response->body());
            }
        } catch (\Throwable $e) {
            Log::channel('otp')->error('SMS_TWILIO_EXCEPTION', [
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
