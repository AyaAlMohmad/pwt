<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $email = $request->input('email') ?? $user->email;

        if ($email === null) {
            return ApiResponse::error(__('auth.email_missing'), 422, null, 'ERR_EMAIL_MISSING');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ApiResponse::error(__('auth.email_invalid'), 422, null, 'ERR_EMAIL_INVALID');
        }

        $stale = User::query()
            ->where('email', $email)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if ($stale !== null) {
            if ($stale->is_active === true) {
                return ApiResponse::error(__('auth.email_taken'), 422, null, 'ERR_EMAIL_TAKEN');
            }

            $stale->tokens()->delete();
            $stale->forceDelete();
        }

        $otp = $this->otpService->createOtp(
            $email,
            OtpPurpose::EmailVerification,
            $request->ip(),
            false,
        );

        try {
            Mail::raw(
                "Your PWT email verification code is: {$otp->plainCode}\nValid for 5 minutes.",
                static function ($message) use ($email): void {
                    $message->to($email)->subject('PWT Email Verification');
                },
            );
        } catch (\Throwable $e) {
            Log::error('EMAIL_OTP_SEND_FAILED', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return ApiResponse::success(
            $this->otpEnvelope($otp),
            __('auth.email_otp_sent'),
        );
    }

    public function verify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $email = $request->input('email') ?? $user->email;

        if ($email === null) {
            return ApiResponse::error(__('auth.email_missing'), 422, null, 'ERR_EMAIL_MISSING');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ApiResponse::error(__('auth.email_invalid'), 422, null, 'ERR_EMAIL_INVALID');
        }

        $stale = User::query()
            ->where('email', $email)
            ->where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if ($stale !== null) {
            if ($stale->is_active === true) {
                return ApiResponse::error(__('auth.email_taken'), 422, null, 'ERR_EMAIL_TAKEN');
            }

            $stale->tokens()->delete();
            $stale->forceDelete();
        }

        $code = (string) $request->input('otp');
        if (! is_numeric($code) || strlen($code) !== 6) {
            return ApiResponse::error(__('auth.otp_invalid'), 422, null, 'ERR_OTP_INVALID');
        }

        $this->otpService->verifyOtp($email, $code, OtpPurpose::EmailVerification);

        $user->forceFill([
            'email' => $email,
            'email_verified_at' => now(),
        ])->save();

        return ApiResponse::success(
            ['email_verified_at' => $user->email_verified_at?->toIso8601String()],
            __('auth.email_otp_verified'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function otpEnvelope(Otp $otp): array
    {
        $payload = [
            'otp_id' => $otp->public_id,
            'purpose' => $otp->purpose->value,
            'expires_at' => $otp->expires_at->toIso8601String(),
        ];

        if ($otp->devCode !== null) {
            $payload['dev_otp'] = $otp->devCode;
        }

        return $payload;
    }
}
