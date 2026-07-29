<?php

declare(strict_types=1);

use App\Exceptions\AppException;
use App\Exceptions\Auth\AuthException;
use App\Http\Middleware\AdminGuard;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyPaymentWebhookSignature;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The `admin` middleware alias is applied inside
            // routes/api_admin.php on a per-group basis so the public
            // admin-login endpoint can opt out. See Phase 14 spec
            // (docs/architecture/admin-auth.md § 1.1).
            Route::middleware(['api'])
                ->prefix('api/v1/admin')
                ->name('admin.')
                ->group(base_path('routes/api_admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SetLocale::class,
        ]);

        $middleware->alias([
            'admin' => AdminGuard::class,
            'ability' => CheckAbilities::class,
            'webhook.signature' => VerifyPaymentWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $shouldHandle = static fn (Request $request): bool => $request->expectsJson()
            || $request->is('api/*');

        $exceptions->render(function (AuthException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                $e->getMessage(),
                $e->status,
                $e->errors,
                $e->errorCode,
            );
        });

        $exceptions->render(function (AppException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                $e->getMessage(),
                $e->status,
                $e->errors,
                $e->errorCode,
            );
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : __('messages.validation_failed'),
                422,
                $e->errors(),
                'ERR_VALIDATION',
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                __('messages.unauthenticated'),
                401,
                code: 'ERR_UNAUTHENTICATED',
            );
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : __('messages.forbidden'),
                403,
                code: 'ERR_FORBIDDEN',
            );
        });

        // Laravel 11 re-wraps AuthorizationException (thrown by `$this->authorize()`)
        // into AccessDeniedHttpException before our AuthorizationException renderer
        // can run, which would otherwise land on the generic HttpExceptionInterface
        // branch and return ERR_HTTP. Catch the wrapped form explicitly so every
        // controller using `$this->authorize(...)` returns a contract-correct
        // ERR_FORBIDDEN envelope.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            $previous = $e->getPrevious();
            $message = $previous instanceof AuthorizationException && $previous->getMessage() !== ''
                ? $previous->getMessage()
                : ($e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage()
                    : __('messages.forbidden'));

            return ApiResponse::error(
                $message,
                403,
                code: 'ERR_FORBIDDEN',
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                __('messages.not_found'),
                404,
                code: 'ERR_NOT_FOUND',
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                __('messages.not_found'),
                404,
                code: 'ERR_NOT_FOUND',
            );
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            return ApiResponse::error(
                __('messages.rate_limited'),
                429,
                code: 'ERR_RATE_LIMITED',
            );
        });

        $exceptions->render(function (HttpResponseException $e, Request $request) {
            // Pass-through: Laravel/Sanctum may throw this with a fully-formed response.
            return $e->getResponse();
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($shouldHandle) {
            if (! $shouldHandle($request)) {
                return null;
            }

            // Re-throw cases already handled above so we don't double-wrap.
            if ($e instanceof AuthException
                || $e instanceof AppException
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof AccessDeniedHttpException
                || $e instanceof ModelNotFoundException
                || $e instanceof NotFoundHttpException
                || $e instanceof ThrottleRequestsException
                || $e instanceof HttpResponseException) {
                return null;
            }

            // Honour any explicit HTTP exception status while keeping the envelope.
            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error(
                    $e->getMessage() !== '' ? $e->getMessage() : __('messages.server_error'),
                    $e->getStatusCode(),
                    code: 'ERR_HTTP',
                );
            }

            $payload = null;
            if (config('app.debug') === true) {
                $payload = [
                    'exception' => [
                        $e::class => $e->getMessage(),
                    ],
                ];
            }

            return ApiResponse::error(
                config('app.debug') === true ? $e->getMessage() : __('messages.server_error'),
                500,
                $payload,
                'ERR_SERVER',
            );
        });
    })->create();
