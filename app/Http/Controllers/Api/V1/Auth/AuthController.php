<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Enums\ResetChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginOtpRequest;
use App\Http\Requests\Api\V1\Auth\LoginOtpVerifyRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterOtpRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyResetOtpRequest;
use App\Http\Resources\Api\V1\Auth\AuthTokenResource;
use App\Http\Resources\Api\V1\Auth\LoginResponseResource;
use App\Models\Otp;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use App\Services\Auth\PasswordResetService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

final class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuthService $authService,
        private readonly PasswordResetService $passwordResetService,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/register-otp',
        operationId: 'authRegisterOtp',
        description: 'Sends a 6-digit registration OTP to the supplied phone, valid 5 minutes. Always returns 200 regardless of phone existence (anti-enumeration). In non-production envs the OTP is also returned via `data.dev_otp` for development convenience.',
        summary: 'Request a registration OTP',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RegisterOtpRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OTP sent (generic).',
                content: new OA\JsonContent(ref: '#/components/schemas/OtpRequestResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function registerOtp(RegisterOtpRequest $request): JsonResponse
    {
        $otp = $this->otpService->requestOtp(
            (string) $request->input('phone_e164'),
            OtpPurpose::Register,
            $request->ip(),
        );

        return ApiResponse::success(
            $this->otpEnvelope($otp),
            __('auth.otp_sent'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/verify-otp',
        operationId: 'authVerifyOtp',
        description: 'Verifies a 6-digit registration OTP and returns a short-lived registration token (Sanctum, ability `register-only`, 15-minute TTL).',
        summary: 'Verify a registration OTP',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/VerifyOtpRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OTP verified.',
                content: new OA\JsonContent(ref: '#/components/schemas/OtpVerifiedResponse')),
            new OA\Response(response: 422, description: 'Invalid / expired / exhausted OTP',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_e164');
        $code = (string) $request->input('otp');

        $otp = $this->otpService->verifyOtp($phone, $code, OtpPurpose::Register);
        $accountType = (string) $request->input('account_type');
        $token = $this->authService->issueRegistrationToken($otp, $accountType);

        return ApiResponse::success(
            (new AuthTokenResource($token))->resolve(),
            __('auth.otp_verified'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/register',
        operationId: 'authRegister',
        description: 'Completes registration using the registration token issued by /auth/verify-otp. The phone is sourced server-side from the token and CANNOT be supplied in the request body. Email is optional in MVP (deviation from comments.xlsx row 2; see docs/architecture/auth.md § 9.1).',
        summary: 'Complete registration with a verified registration token',
        security: [['sanctum' => ['register-only']]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthSuccessResponse')),
            new OA\Response(response: 401, description: 'Missing or expired registration token',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Token ability insufficient',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Phone or email already taken',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();

        $payload = [
            'name' => (string) $request->input('name'),
            'email' => $request->input('email'),
            'password' => (string) $request->input('password'),
            'locale' => $request->input('locale'),
            'account_type' => (string) $request->input('account_type'),
            'company_name' => $request->input('company_name'),
        ];

        $result = $this->authService->completeRegistration($token, $payload);

        return ApiResponse::success(
            (new LoginResponseResource($result))->resolve(),
            __('auth.register_success'),
            201,
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'authLogin',
        description: 'Authenticate with phone OR email plus password. Returns the user and a 30-day Sanctum access token.',
        summary: 'Password login',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthSuccessResponse')),
            new OA\Response(response: 401, description: 'Invalid credentials',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginWithPassword(
            $request->input('email'),
            $request->input('phone_e164'),
            (string) $request->input('password'),
            (string) $request->input('account_type'),
        );

        return ApiResponse::success(
            (new LoginResponseResource($result))->resolve(),
            __('auth.login_success'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/login-otp',
        operationId: 'authLoginOtp',
        description: 'Send a passwordless login OTP to the supplied phone. Always returns 200 (anti-enumeration). In non-production envs `data.dev_otp` carries the code for development convenience.',
        summary: 'Request a passwordless login OTP',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginOtpRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OTP sent (generic).',
                content: new OA\JsonContent(ref: '#/components/schemas/OtpRequestResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function loginOtp(LoginOtpRequest $request): JsonResponse
    {
        $otp = $this->otpService->requestOtp(
            (string) $request->input('phone_e164'),
            OtpPurpose::Login,
            $request->ip(),
        );

        return ApiResponse::success(
            $this->otpEnvelope($otp),
            __('auth.otp_sent'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/login-otp/verify',
        operationId: 'authLoginOtpVerify',
        description: 'Verify a login OTP and exchange it for a 30-day access token directly. Disabled or soft-deleted accounts always return 401 (no enumeration leak).',
        summary: 'Verify a passwordless login OTP',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/VerifyLoginOtpRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AuthSuccessResponse')),
            new OA\Response(response: 401, description: 'Account disabled',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid OTP',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function loginOtpVerify(LoginOtpVerifyRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone_e164');
        $this->otpService->verifyOtp($phone, (string) $request->input('otp'), OtpPurpose::Login);

        $result = $this->authService->loginWithVerifiedOtp(
            $phone,
            (string) $request->input('account_type'),
        );

        return ApiResponse::success(
            (new LoginResponseResource($result))->resolve(),
            __('auth.login_success'),
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'authLogout',
        description: 'Revokes the current access token. Other tokens belonging to the same user are unaffected. Use DELETE /me to revoke ALL tokens.',
        summary: 'Logout (current token only)',
        security: [['sanctum' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/LogoutResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken|null $token */
        $token = $request->user()?->currentAccessToken();
        $token?->delete();

        return ApiResponse::success(null, __('auth.logout_success'));
    }

    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        operationId: 'authForgotPassword',
        description: 'Request a password reset via email (link) or phone (OTP). Always returns 200 with a generic message regardless of whether the identifier matches a user (anti-enumeration; deviation from comments.xlsx row 5 per architecture § 9.4).',
        summary: 'Request a password reset',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ForgotPasswordRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'Generic success',
                content: new OA\JsonContent(ref: '#/components/schemas/ForgotPasswordResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->input('email');
        $phone = $request->input('phone_e164');
        $channel = $email !== null ? ResetChannel::Email : ResetChannel::Phone;
        $identifier = $email ?? (string) $phone;

        $reset = $this->passwordResetService->requestReset($identifier, $channel, $request->ip());

        $payload = [
            'channel' => $channel->value,
            'expires_at' => $reset->expires_at->toIso8601String(),
        ];

        if (! app()->environment('production') && $reset->devSecret !== null) {
            $payload['dev_otp'] = $reset->devSecret;
        }

        return ApiResponse::success($payload, __('auth.reset_sent'));
    }

    #[OA\Post(
        path: '/api/v1/auth/verify-reset-otp',
        operationId: 'authVerifyResetOtp',
        description: 'Verify the 6-digit OTP sent to the email address and receive a short-lived reset token.',
        summary: 'Verify reset OTP (email channel)',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'email', type: 'string', example: 'user@example.com'),
                    new OA\Property(property: 'otp', type: 'string', example: '123456'),
                ],
            ),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OTP verified; reset token issued'),
            new OA\Response(response: 422, description: 'OTP invalid or expired',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function verifyResetOtp(VerifyResetOtpRequest $request): JsonResponse
    {
        $email = (string) $request->input('email');
        $otp = (string) $request->input('otp');

        $reset = $this->passwordResetService->verifyResetOtp($email, $otp);

        $payload = [
            'expires_at' => $reset->expires_at->toIso8601String(),
        ];

        if (! app()->environment('production') && $reset->devSecret !== null) {
            $payload['dev_token'] = $reset->devSecret;
        }

        return ApiResponse::success($payload, __('auth.otp_verified'));
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password',
        operationId: 'authResetPassword',
        description: 'Complete a password reset via email+token OR phone+otp. On success ALL Sanctum tokens for the user are revoked and the user must re-login.',
        summary: 'Complete password reset',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ResetPasswordRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/ResetPasswordResponse')),
            new OA\Response(response: 422, description: 'Token / OTP invalid or validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $password = (string) $request->input('password');

        if ($request->filled('email')) {
            $this->passwordResetService->consumeEmailReset(
                (string) $request->input('email'),
                (string) $request->input('token'),
                $password,
            );
        } else {
            $this->passwordResetService->consumePhoneReset(
                (string) $request->input('phone_e164'),
                (string) $request->input('otp'),
                $password,
            );
        }

        return ApiResponse::success(null, __('auth.reset_success'));
    }

    /**
     * Build the `data` payload for OTP request responses.
     *
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
