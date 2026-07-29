<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuditLogs\AdminAuditLogsController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\Catalog\AdminCategoriesController;
use App\Http\Controllers\Api\V1\Admin\Catalog\AdminProductsController;
use App\Http\Controllers\Api\V1\Admin\Content\AdminBannersController;
use App\Http\Controllers\Api\V1\Admin\Content\AdminPromotionsController;
use App\Http\Controllers\Api\V1\Admin\Content\AdminTestimonialsController;
use App\Http\Controllers\Api\V1\Admin\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\Machines\AdminMachinesController;
use App\Http\Controllers\Api\V1\Admin\Maintenance\AdminMaintenanceController;
use App\Http\Controllers\Api\V1\Admin\Mail\AdminMailSettingsController;
use App\Http\Controllers\Api\V1\Admin\Sms\AdminSmsSettingsController;
use App\Http\Controllers\Api\V1\Admin\Orders\AdminOrdersController;
use App\Http\Controllers\Api\V1\Admin\Payments\AdminPaymentSettingsController;
use App\Http\Controllers\Api\V1\Admin\Reports\AdminReportsController;
use App\Http\Controllers\Api\V1\Admin\Rfq\AdminRfqController;
use App\Http\Controllers\Api\V1\Admin\Support\AdminSupportTicketsController;
use App\Http\Controllers\Api\V1\Admin\Templates\AdminTemplatesController;
use App\Http\Controllers\Api\V1\Admin\Users\AdminUsersController;
use App\Models\Banner;
use App\Models\Promotion;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Route;

// Phase 20 — Admin Content (Banners / Promotions / Testimonials).
// Implicit-binding overrides so admin views surface soft-deleted rows.
// Customer-side (Phase 7) reads continue to use the default `Active` scope
// on the same models; these overrides are scoped to admin routes only.
Route::bind('banner', static fn (string|int $value): Banner => Banner::withTrashed()
    ->findOrFail((int) $value));
Route::bind('promotion', static fn (string|int $value): Promotion => Promotion::withTrashed()
    ->findOrFail((int) $value));
Route::bind('testimonial', static fn (string|int $value): Testimonial => Testimonial::withTrashed()
    ->findOrFail((int) $value));

/*
|--------------------------------------------------------------------------
| Admin API routes (v1)
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the /api/v1/admin prefix. The
| `admin` middleware alias is applied per-group below so the public
| /auth/login endpoint can opt out — see docs/architecture/admin-auth.md
| § 1.1. Every other admin route MUST live inside the auth:sanctum +
| admin guarded group.
*/

// Phase 14 — Admin Auth
Route::post('/auth/login', [AdminAuthController::class, 'login'])
    ->middleware('throttle:auth-strict')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::post('/auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
    Route::get('/me', [AdminAuthController::class, 'me'])->name('me');

    // Phase 15 — Admin Users management
    Route::apiResource('users', AdminUsersController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->whereNumber('user')
        ->names([
            'index' => 'users.index',
            'store' => 'users.store',
            'show' => 'users.show',
            'update' => 'users.update',
            'destroy' => 'users.destroy',
        ]);

    Route::get('/technicians', [AdminUsersController::class, 'technicians'])
        ->name('technicians.index');

    // Phase 16 — Admin Catalog (categories + products + images + status)
    Route::apiResource('categories', AdminCategoriesController::class)
        ->whereNumber('category');

    Route::apiResource('products', AdminProductsController::class)
        ->whereNumber('product');

    // Product status toggle (PATCH .../{id}/status) and image attach/detach.
    // Path params use {id}, {productId}, {imageId} (not bound to the
    // implicit {product} on apiResource so the service does the trashed
    // lookup itself, matching the admin-users module).
    Route::patch('products/{id}/status', [AdminProductsController::class, 'updateStatus'])
        ->whereNumber('id')
        ->name('products.status.update');

    Route::post('products/{id}/images', [AdminProductsController::class, 'attachImage'])
        ->whereNumber('id')
        ->name('products.images.store');

    Route::delete('products/{productId}/images/{imageId}', [AdminProductsController::class, 'detachImage'])
        ->whereNumber('productId')
        ->whereNumber('imageId')
        ->name('products.images.destroy');

    // Phase 17 — Admin Orders. All six endpoints are explicit so the
    // service can do the `withTrashed()` lookup and PATCH /status can
    // re-route `scheduled` requests to the dedicated endpoint.
    Route::prefix('orders')->name('orders.')->group(function (): void {
        Route::get('/', [AdminOrdersController::class, 'index'])
            ->name('index');
        Route::get('/{id}', [AdminOrdersController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
        Route::patch('/{id}/status', [AdminOrdersController::class, 'updateStatus'])
            ->whereNumber('id')
            ->name('update-status');
        Route::post('/{id}/cancel', [AdminOrdersController::class, 'cancel'])
            ->whereNumber('id')
            ->name('cancel');
        Route::post('/{id}/schedule-installation', [AdminOrdersController::class, 'scheduleInstallation'])
            ->whereNumber('id')
            ->name('schedule-installation');
        Route::post('/{id}/notes', [AdminOrdersController::class, 'addNote'])
            ->whereNumber('id')
            ->name('add-note');
    });

    // Phase 18 — Admin Machines. /due-maintenance is declared BEFORE
    // /{id} to avoid route precedence ambiguity.
    Route::prefix('machines')->name('machines.')->group(function (): void {
        Route::get('due-maintenance', [AdminMachinesController::class, 'dueMaintenance'])
            ->name('due-maintenance');
        Route::get('/', [AdminMachinesController::class, 'index'])
            ->name('index');
        Route::post('/', [AdminMachinesController::class, 'store'])
            ->name('store');
        Route::get('/{id}', [AdminMachinesController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
        Route::put('/{id}', [AdminMachinesController::class, 'update'])
            ->whereNumber('id')
            ->name('update');
    });

    // Phase 18 — Admin Maintenance Requests.
    Route::prefix('maintenance-requests')->name('maintenance.')->group(function (): void {
        Route::get('/', [AdminMaintenanceController::class, 'index'])
            ->name('index');
        Route::get('/{id}', [AdminMaintenanceController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
        Route::post('/', [AdminMaintenanceController::class, 'store'])
            ->name('store');
        Route::patch('/{id}/assign', [AdminMaintenanceController::class, 'assign'])
            ->whereNumber('id')
            ->name('assign');
        Route::patch('/{id}/status', [AdminMaintenanceController::class, 'updateStatus'])
            ->whereNumber('id')
            ->name('status');
        Route::post('/{id}/close', [AdminMaintenanceController::class, 'close'])
            ->whereNumber('id')
            ->name('close');
    });

    // Phase 19 — Admin Support Tickets.
    Route::prefix('support-tickets')->name('support.')->group(function (): void {
        Route::get('/', [AdminSupportTicketsController::class, 'index'])->name('index');
        Route::get('/{id}', [AdminSupportTicketsController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::patch('/{id}', [AdminSupportTicketsController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::post('/{id}/reply', [AdminSupportTicketsController::class, 'reply'])
            ->whereNumber('id')->name('reply');
    });

    // Phase 20 — Admin Content (Banners / Promotions / Testimonials).
    // Implicit binding withTrashed() is registered at the top of this
    // file so admins see soft-deleted records on show / update / destroy.
    Route::apiResource('banners', AdminBannersController::class)
        ->whereNumber('banner');

    Route::apiResource('promotions', AdminPromotionsController::class)
        ->whereNumber('promotion');

    Route::apiResource('testimonials', AdminTestimonialsController::class)
        ->whereNumber('testimonial');

    // Phase 21 — Admin Reports & Exports. Five GET endpoints; the export
    // route uses an enum-style {type} segment validated by `whereIn` and
    // additionally throttled via the `reports-export` named limiter
    // (10/min/user) registered in AppServiceProvider.
    // See docs/architecture/admin-reports.md § 1.1.
    Route::prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/sales', [AdminReportsController::class, 'sales'])->name('sales');
        Route::get('/orders', [AdminReportsController::class, 'orders'])->name('orders');
        Route::get('/maintenance', [AdminReportsController::class, 'maintenance'])->name('maintenance');
        Route::get('/customers', [AdminReportsController::class, 'customers'])->name('customers');
        Route::get('/{type}/export', [AdminReportsController::class, 'export'])
            ->whereIn('type', ['sales', 'orders', 'maintenance', 'customers'])
            ->middleware('throttle:reports-export')
            ->name('export');
    });

    // Phase 22 — Admin Payment Settings (manage gateway keys dynamically).
    Route::get('/payment-settings', [AdminPaymentSettingsController::class, 'show'])
        ->name('payment-settings.show');
    Route::put('/payment-settings', [AdminPaymentSettingsController::class, 'update'])
        ->name('payment-settings.update');

    // Phase 22 — Admin Mail Settings (manage SMTP / mailer config dynamically).
    Route::get('/mail-settings', [AdminMailSettingsController::class, 'show'])
        ->name('mail-settings.show');
    Route::put('/mail-settings', [AdminMailSettingsController::class, 'update'])
        ->name('mail-settings.update');

    // Admin SMS Settings (manage SMS gateway config dynamically).
    Route::get('/sms-settings', [AdminSmsSettingsController::class, 'show'])
        ->name('sms-settings.show');
    Route::put('/sms-settings', [AdminSmsSettingsController::class, 'update'])
        ->name('sms-settings.update');

    // Phase 22 — Admin Audit Logs (super_admin only), Notification
    // Templates editor (super_admin only), and Dashboard KPI snapshot
    // (open to any admin role). See
    // docs/architecture/admin-audit-templates-dashboard.md.
    Route::get('/audit-logs', [AdminAuditLogsController::class, 'index'])
        ->name('audit-logs.index');

    Route::get('/notification-templates', [AdminTemplatesController::class, 'index'])
        ->name('notification-templates.index');
    Route::put('/notification-templates/{id}', [AdminTemplatesController::class, 'update'])
        ->whereNumber('id')
        ->name('notification-templates.update');

    // Phase 22 — Admin RFQ submissions.
    Route::prefix('rfq')->name('rfq.')->group(function (): void {
        Route::get('/', [AdminRfqController::class, 'index'])
            ->name('index');
        Route::get('/{id}', [AdminRfqController::class, 'show'])
            ->whereNumber('id')
            ->name('show');
        Route::patch('/{id}/processed', [AdminRfqController::class, 'markProcessed'])
            ->whereNumber('id')
            ->name('processed');
    });

    Route::get('/dashboard', [AdminDashboardController::class, 'show'])
        ->name('dashboard');
});
