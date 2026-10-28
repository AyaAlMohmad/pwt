<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Addresses\AddressesController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Catalog\CatalogController;
use App\Http\Controllers\Api\V1\Contact\ContactController;
use App\Http\Controllers\Api\V1\Files\FilesController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Maintenance\MaintenanceRequestsController;
use App\Http\Controllers\Api\V1\Me\EmailVerificationController;
use App\Http\Controllers\Api\V1\Me\MachinesController;
use App\Http\Controllers\Api\V1\Notifications\NotificationsController;
use App\Http\Controllers\Api\V1\Orders\OrdersController;
use App\Http\Controllers\Api\V1\Payments\PaymentCardsController;
use App\Http\Controllers\Api\V1\Payments\PaymentsController;
use App\Http\Controllers\Api\V1\Promotions\BannersController;
use App\Http\Controllers\Api\V1\Promotions\PromotionsController;
use App\Http\Controllers\Api\V1\Promotions\TestimonialsController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\Rfq\RfqController;
use App\Http\Controllers\Api\V1\Support\SupportTicketsController;
use App\Http\Controllers\Api\V1\User\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer-facing API routes (v1)
|--------------------------------------------------------------------------
|
| All routes here are prefixed with /api by Laravel and grouped further
| under /v1 below. Admin routes live in routes/api_admin.php and are
| registered separately in bootstrap/app.php.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    // Public reference data (no auth).
    Route::get('/countries', [ReferenceController::class, 'countries'])
        ->name('reference.countries');
    Route::get('/countries/{code}/regions', [ReferenceController::class, 'regions'])
        ->name('reference.regions');
    Route::get('/countries/{code}/cities', [ReferenceController::class, 'cities'])
        ->name('reference.cities');
    Route::get('/settings/public', [ReferenceController::class, 'publicSettings'])
        ->name('reference.public-settings');

    // Phase 13 — public guest contact form. No Sanctum. Rate-limited at
    // 5/min/IP via the `contact-form` named limiter so spambots can't
    // flood the ops inbox. See docs/architecture/contact.md § 1.1.
    Route::post('/contact', [ContactController::class, 'store'])
        ->middleware('throttle:contact-form')
        ->name('contact.store');

    // Phase 22 — public guest RFQ form. No Sanctum. Rate-limited at
    // 5/min/IP via the `rfq.create` named limiter.
    Route::post('/rfq', [RfqController::class, 'store'])
        ->middleware('throttle:rfq.create')
        ->name('rfq.store');

    // Customer authentication.
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register-otp', [AuthController::class, 'registerOtp'])
            ->middleware('throttle:otp-request')
            ->name('register-otp');

        Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])
            ->middleware('throttle:otp-verify')
            ->name('verify-otp');

        Route::post('/register', [AuthController::class, 'register'])
            ->middleware(['auth:sanctum', 'ability:register-only'])
            ->name('register');

        // Email verification (OTP over email), usable with the register-only token.
        Route::post('/email-verification', [EmailVerificationController::class, 'send'])
            ->middleware(['auth:sanctum', 'ability:register-only', 'throttle:otp-request'])
            ->name('email-verification.send');
        Route::post('/email-verification/verify', [EmailVerificationController::class, 'verify'])
            ->middleware(['auth:sanctum', 'ability:register-only', 'throttle:otp-verify'])
            ->name('email-verification.verify');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-strict')
            ->name('login');

        Route::post('/login-otp', [AuthController::class, 'loginOtp'])
            ->middleware('throttle:otp-request')
            ->name('login-otp');

        Route::post('/login-otp/verify', [AuthController::class, 'loginOtpVerify'])
            ->middleware('throttle:otp-verify')
            ->name('login-otp.verify');

        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('logout');

        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:forgot-password')
            ->name('forgot-password');

        Route::post('/verify-reset-otp', [AuthController::class, 'verifyResetOtp'])
            ->middleware('throttle:otp-verify')
            ->name('verify-reset-otp');

        Route::post('/reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:auth-strict')
            ->name('reset-password');
    });

    // Authenticated customer profile (self-only).
    Route::prefix('me')->name('me.')->middleware('auth:sanctum')->group(function (): void {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
        Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('avatar');

        // Address book — caller-scoped CRUD. Ownership enforced via
        // AddressPolicy. Per docs/architecture/addresses.md § 1.3 the
        // index is intentionally unpaginated (cap = 10/customer).
        Route::apiResource('addresses', AddressesController::class)
            ->parameters(['addresses' => 'address'])
            ->whereNumber('address')
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::apiResource('payment-cards', PaymentCardsController::class)
            ->parameters(['payment-cards' => 'paymentCard'])
            ->whereNumber('paymentCard')
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::post('/payment-cards/{paymentCard}/default', [PaymentCardsController::class, 'setDefault'])
            ->whereNumber('paymentCard')
            ->name('payment-cards.default');
    });

    // File uploads (authenticated). Upload + delete are bearer-only;
    // download additionally accepts a valid signed URL so the resource
    // can be embedded in emails / push notifications without a token.
    Route::middleware('auth:sanctum')->prefix('files')->name('files.')->group(function (): void {
        Route::post('/', [FilesController::class, 'store'])
            ->middleware('throttle:files-upload')
            ->name('store');
        Route::delete('/{id}', [FilesController::class, 'destroy'])
            ->whereNumber('id')
            ->name('destroy');
    });

    Route::get('/files/{uuid}/download', [FilesController::class, 'download'])
        ->whereUuid('uuid')
        ->name('files.download');

    // Push token registration (FCM) for mobile push notifications.
    Route::middleware('auth:sanctum')->prefix('push-tokens')->name('push-tokens.')->group(function (): void {
        Route::post('/register', [\App\Http\Controllers\Api\V1\Notifications\PushTokenController::class, 'register'])
            ->name('register');
        Route::post('/unregister', [\App\Http\Controllers\Api\V1\Notifications\PushTokenController::class, 'unregister'])
            ->name('unregister');
    });

    // Public catalog (categories + products). Read-only, no auth.
    // Rate limited at 60/min/IP (`public` limiter — CLAUDE.md § 5.7).
    Route::middleware('throttle:public')->group(function (): void {
        Route::prefix('categories')->name('catalog.categories.')->group(function (): void {
            Route::get('/', [CatalogController::class, 'categoriesIndex'])->name('index');
            Route::get('/{id}', [CatalogController::class, 'categoryShow'])
                ->whereNumber('id')
                ->name('show');
        });

        Route::prefix('products')->name('catalog.products.')->group(function (): void {
            Route::get('/', [CatalogController::class, 'productsIndex'])->name('index');
            Route::get('/best-sellers', [CatalogController::class, 'bestSellers'])->name('best-sellers');
            Route::get('/{id}', [CatalogController::class, 'productShow'])
                ->whereNumber('id')
                ->name('show');
        });

        // Phase 7 — Banners, Promotions list, Testimonials.
        // All three are bounded, unpaginated, guest-readable.
        Route::get('/banners', [BannersController::class, 'index'])->name('banners.index');
        Route::get('/promotions', [PromotionsController::class, 'index'])->name('promotions.index');
        Route::get('/testimonials', [TestimonialsController::class, 'index'])->name('testimonials.index');
    });

    // Phase 7 — promo-code validation. Authenticated. Tight rate limit
    // (10/min/user) to deter brute-force code enumeration; § 6.3 of
    // docs/architecture/promotions.md explains the trade-off.
    Route::middleware(['auth:sanctum', 'throttle:promotions.validate'])->group(function (): void {
        Route::post('/promotions/validate', [PromotionsController::class, 'validate'])
            ->name('promotions.validate');
    });

    // Phase 8 — Customer Orders. Two named limiters tighten the write
    // endpoints below the default api 120/min/user. See
    // docs/architecture/orders.md § 1.4.
    Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function (): void {
        Route::post('/', [OrdersController::class, 'store'])
            ->middleware('throttle:orders.create')
            ->name('store');
        Route::get('/dashboard', [OrdersController::class, 'dashboard'])->name('dashboard');
        Route::get('/', [OrdersController::class, 'index'])->name('index');
        Route::get('/{order}', [OrdersController::class, 'show'])
            ->whereNumber('order')
            ->name('show');
        Route::post('/{order}/cancel', [OrdersController::class, 'cancel'])
            ->whereNumber('order')
            ->middleware('throttle:orders.cancel')
            ->name('cancel');
        Route::get('/{order}/tracking', [OrdersController::class, 'tracking'])
            ->whereNumber('order')
            ->name('tracking');
    });

    // Phase 9 — Payments. Webhook is public + signature middleware so
    // the gateway can call without Sanctum. /initiate + /status sit
    // under auth:sanctum. See docs/architecture/payments.md § 1.2.
    Route::middleware('auth:sanctum')->prefix('payments')->name('payments.')->group(function (): void {
        Route::post('/initiate', [PaymentsController::class, 'initiate'])
            ->middleware('throttle:payments.initiate')
            ->name('initiate');
        Route::post('/create-session', [PaymentsController::class, 'createSession'])
            ->middleware('throttle:payments.initiate')
            ->name('create-session');
        Route::get('/{id}/status', [PaymentsController::class, 'status'])
            ->whereNumber('id')
            ->name('status');
    });

    Route::post('/payments/webhook', [PaymentsController::class, 'webhook'])
        ->middleware(['throttle:webhook', 'webhook.signature'])
        ->name('payments.webhook');

    // Phase 10 — Machines (customer-owned) + Maintenance Requests.
    // Rental payments — manual retry after a failed automatic charge.
    // {machine} is the machine_id so the mobile app does not need to
    // know the internal schedule id.
    Route::middleware('auth:sanctum')->prefix('rentals')->name('rentals.')->group(function (): void {
        Route::post('/{machine}/retry-payment', [\App\Http\Controllers\Api\V1\Rentals\RentalPaymentsController::class, 'retry'])
            ->whereNumber('machine')
            ->middleware('throttle:payments.initiate')
            ->name('retry-payment');
    });

    // /me/machines is read-only here; admin create/edit lives in Phase 18.
    // POST /maintenance-requests carries a 10/min/user limiter
    // (`maintenance.create` named limiter in AppServiceProvider).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::prefix('me/machines')->name('me.machines.')->group(function (): void {
            Route::get('/', [MachinesController::class, 'index'])->name('index');
            Route::get('/{id}', [MachinesController::class, 'show'])
                ->whereNumber('id')
                ->name('show');
        });

        Route::prefix('maintenance-requests')->name('maintenance.')->group(function (): void {
            Route::post('/', [MaintenanceRequestsController::class, 'store'])
                ->middleware('throttle:maintenance.create')
                ->name('store');
            Route::get('/', [MaintenanceRequestsController::class, 'index'])->name('index');
            Route::get('/{id}', [MaintenanceRequestsController::class, 'show'])
                ->whereNumber('id')
                ->name('show');
            Route::post('/{id}/reschedule', [MaintenanceRequestsController::class, 'reschedule'])
                ->whereNumber('id')
                ->name('reschedule');
            Route::post('/{id}/cancel', [MaintenanceRequestsController::class, 'cancel'])
                ->whereNumber('id')
                ->name('cancel');
        });

        // Phase 11 — Notifications inbox. Owner-scoped via NotificationPolicy.
        // See docs/architecture/notifications.md § 1.
        Route::prefix('notifications')->name('notifications.')->group(function (): void {
            Route::get('/', [NotificationsController::class, 'index'])->name('index');
            Route::patch('/{id}/read', [NotificationsController::class, 'markRead'])
                ->whereNumber('id')
                ->name('mark-read');
            Route::post('/read-all', [NotificationsController::class, 'markAllRead'])
                ->name('read-all');
        });

        // Phase 12 — Customer-facing support tickets. POST is gated by
        // the tight `support.create` named limiter (10/min/user) per
        // docs/architecture/support.md § 1.2.
        Route::prefix('support-tickets')->name('support.')->group(function (): void {
            Route::post('/', [SupportTicketsController::class, 'store'])
                ->middleware('throttle:support.create')
                ->name('store');
            Route::get('/', [SupportTicketsController::class, 'index'])->name('index');
            Route::get('/{id}', [SupportTicketsController::class, 'show'])
                ->whereNumber('id')
                ->name('show');
            Route::post('/{id}/cancel', [SupportTicketsController::class, 'cancel'])
                ->whereNumber('id')
                ->name('cancel');
        });

        // Phase 22 — Shopping Cart (authenticated customers).
        Route::prefix('cart')->name('cart.')->middleware('throttle:cart')->group(function (): void {
            Route::get('/', [CartController::class, 'show'])->name('show');
            Route::post('/items', [CartController::class, 'addItem'])->name('items.store');
            Route::patch('/items/{id}', [CartController::class, 'updateItem'])
                ->whereNumber('id')
                ->name('items.update');
            Route::delete('/items/{id}', [CartController::class, 'removeItem'])
                ->whereNumber('id')
                ->name('items.destroy');
            Route::delete('/', [CartController::class, 'clear'])->name('clear');
        });
    });
});
