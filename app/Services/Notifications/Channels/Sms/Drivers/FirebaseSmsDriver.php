<?php

declare(strict_types=1);

namespace App\Services\Notifications\Channels\Sms\Drivers;

use App\Services\Notifications\Channels\Sms\SmsDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase SMS driver — sends SMS verification via Firebase Auth REST API.
 *
 * This driver sends SMS OTP codes through Firebase Authentication.
 * Note: The message content is ignored; Firebase generates its own OTP message.
 * Use this driver ONLY for OTP/verification scenarios.
 *
 * Requires driver_config:
 *   - api_key       (string) Firebase Web API Key
 *   - project_id    (string) Firebase Project ID (optional, for status checks)
 *   - use_onesignal (bool)   Use OneSignal-like payload format (default: false)
 */
final class FirebaseSmsDriver implements SmsDriver
{
    private const FIREBASE_AUTH_BASE = 'https://identitytoolkit.googleapis.com/v1';

    public function send(string $to, string $message, array $context = []): void
    {
        $config = config('sms.driver_config.' . config('sms.active_driver_key', 'firebase_sms'), []);

        $apiKey = $config['api_key'] ?? null;

        if (empty($apiKey)) {
            Log::warning('SMS_FIREBASE_MISSING_CONFIG', [
                'to'      => $to,
                'context' => $context,
            ]);

            throw new \RuntimeException('Firebase SMS configuration missing: api_key');
        }

        // Firebase Auth sends its own SMS; we cannot customize the body.
        // We send the phone number to request an OTP code.
        $url = self::FIREBASE_AUTH_BASE . "/accounts:sendVerificationCode?key={$apiKey}";

        $payload = [
            'phoneNumber' => $to,
            'recaptchaToken' => $config['recaptcha_token'] ?? null,
        ];

        // If reCAPTCHA token is not provided, we skip it (only works for trusted environments)
        if (empty($payload['recaptchaToken'])) {
            unset($payload['recaptchaToken']);
        }

        try {
            $response = Http::post($url, $payload);
            $data     = $response->json();

            if ($response->successful() && isset($data['sessionInfo'])) {
                Log::channel('otp')->info('SMS_FIREBASE_SENT', [
                    'to'         => $to,
                    'sessionInfo'=> $data['sessionInfo'],
                    'context'    => $context,
                    'sent_at'    => Carbon::now()->toIso8601String(),
                ]);
            } else {
                $errorCode = $data['error']['code'] ?? 'unknown';
                $errorMsg  = $data['error']['message'] ?? $response->body();

                Log::channel('otp')->error('SMS_FIREBASE_FAILED', [
                    'to'        => $to,
                    'status'    => $response->status(),
                    'errorCode' => $errorCode,
                    'errorMsg'  => $errorMsg,
                    'response'  => $data,
                    'context'   => $context,
                    'sent_at'   => Carbon::now()->toIso8601String(),
                ]);

                throw new \RuntimeException("Firebase SMS error [{$errorCode}]: {$errorMsg}");
            }
        } catch (\Throwable $e) {
            Log::channel('otp')->error('SMS_FIREBASE_EXCEPTION', [
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
