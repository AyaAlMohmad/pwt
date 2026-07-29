<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\ReportType;
use App\Models\Address;
use App\Models\Banner;
use App\Models\Category;
use App\Models\File;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentCard;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\RfqRequest;
use App\Models\SupportTicket;
use App\Models\Testimonial;
use App\Models\User;
use App\Policies\AddressPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BannerPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\FilePolicy;
use App\Policies\MachinePolicy;
use App\Policies\MaintenanceRequestPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\NotificationTemplatePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentCardPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PromotionPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RfqRequestPolicy;
use App\Policies\SupportTicketPolicy;
use App\Policies\TestimonialPolicy;
use App\Policies\UserPolicy;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use App\Services\Notifications\Channels\Sms\SmsDriver;
use App\Services\Payments\PaymentGateway;
use App\Models\Setting;
use App\Support\Http\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsDriver::class, function ($app): SmsDriver {
            $driver = (string) config('sms.default', 'stub');
            $source = 'config';

            try {
                $fromDb = Setting::get('sms.driver');
                if (is_string($fromDb) && $fromDb !== '') {
                    $driver = $fromDb;
                    $source = 'database';
                }

                $driverConfig = Setting::get('sms.driver_config');
                if (is_array($driverConfig)) {
                    config(["sms.driver_config.{$driver}" => $driverConfig]);
                }
            } catch (\Throwable) {
                // Database may not be available during config:cache / bootstrap.
            }

            $classMap = config('sms.drivers');
            $fallbackReason = null;

            $class = is_array($classMap) && isset($classMap[$driver]) && is_string($classMap[$driver]) && class_exists($classMap[$driver])
                ? $classMap[$driver]
                : null;

            if (! is_array($classMap)) {
                $fallbackReason = 'sms.drivers config is not an array';
            } elseif (! isset($classMap[$driver])) {
                $fallbackReason = "driver '{$driver}' not found in sms.drivers map";
            } elseif (! is_string($classMap[$driver])) {
                $fallbackReason = "driver '{$driver}' in sms.drivers is not a string (value: " . gettype($classMap[$driver]) . ')';
            } elseif (! class_exists($classMap[$driver])) {
                $fallbackReason = "class '{$classMap[$driver]}' for driver '{$driver}' does not exist";
            }

            if ($class === null || ! class_exists($class)) {
                $fallback = (string) config('sms.drivers.stub');
                $class = class_exists($fallback) ? $fallback : StubSmsDriver::class;
                $driver = 'stub';
                $source = 'fallback';
            }

            Log::info('SMS_DRIVER_RESOLVED', [
                'driver_key' => $driver,
                'driver_class' => $class,
                'source' => $source,
                'fallback_reason' => $fallbackReason,
                'drivers_map' => is_array($classMap) ? array_keys($classMap) : null,
            ]);

            config(['sms.active_driver_key' => $driver]);

            /** @var SmsDriver $instance */
            $instance = $app->make($class);

            return $instance;
        });

        // Phase 9 — PaymentGateway resolves to the active driver class.
        // COD is NOT a driver; PaymentService short-circuits it before
        // touching this binding.
        // Driver key is read from settings first (admin-updatable) then config.
        $this->app->bind(PaymentGateway::class, function ($app): PaymentGateway {
            $driver = (string) config('payment.default', 'stub');

            try {
                $fromDb = \App\Models\Setting::get('payment.default');
                if (is_string($fromDb) && $fromDb !== '') {
                    $driver = $fromDb;
                }
            } catch (\Throwable) {
                // Database may not be available during config:cache / bootstrap.
            }

            /** @var class-string<PaymentGateway> $class */
            $class = (string) config("payment.drivers.$driver");

            if ($class === '' || ! class_exists($class)) {
                $class = (string) config('payment.drivers.stub');
            }

            /** @var PaymentGateway $instance */
            $instance = $app->make($class);

            return $instance;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();
        $this->registerPolicies();
        $this->configureMailFromDatabase();
        $this->configureSmsFromDatabase();
    }

    private function configureMailFromDatabase(): void
    {
        try {
            $settings = [
                'mail.default' => Setting::get('mail.driver'),
                'mail.mailers.smtp.host' => Setting::get('mail.host'),
                'mail.mailers.smtp.port' => Setting::get('mail.port'),
                'mail.mailers.smtp.encryption' => Setting::get('mail.encryption'),
                'mail.mailers.smtp.username' => Setting::get('mail.username'),
                'mail.mailers.smtp.password' => Setting::get('mail.password'),
                'mail.from.address' => Setting::get('mail.from_address'),
                'mail.from.name' => Setting::get('mail.from_name'),
            ];

            foreach ($settings as $key => $value) {
                if ($value !== null && $value !== '') {
                    config([$key => $value]);
                }
            }
        } catch (\Throwable) {
            // DB may not be available during config:cache / bootstrap.
        }
    }

    private function configureSmsFromDatabase(): void
    {
        try {
            $driver = Setting::get('sms.driver');
            if (is_string($driver) && $driver !== '') {
                config(['sms.default' => $driver]);
            }

            $sender = Setting::get('sms.sender');
            if (is_string($sender) && $sender !== '') {
                config(['sms.sender' => $sender]);
            }

            $driverConfig = Setting::get('sms.driver_config');
            if (is_array($driverConfig)) {
                $activeDriver = is_string($driver) && $driver !== '' ? $driver : config('sms.default', 'stub');
                config(["sms.driver_config.{$activeDriver}" => $driverConfig]);
            }

            Log::info('SMS_CONFIG_LOADED_FROM_DB', [
                'driver' => $driver,
                'sender' => $sender,
                'driver_config' => $driverConfig,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SMS_CONFIG_DB_LOAD_FAILED', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function registerPolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(File::class, FilePolicy::class);
        Gate::policy(Address::class, AddressPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PaymentCard::class, PaymentCardPolicy::class);
        Gate::policy(Machine::class, MachinePolicy::class);
        Gate::policy(MaintenanceRequest::class, MaintenanceRequestPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Banner::class, BannerPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);
        Gate::policy(Testimonial::class, TestimonialPolicy::class);
        Gate::policy(NotificationTemplate::class, NotificationTemplatePolicy::class);
        Gate::policy(RfqRequest::class, RfqRequestPolicy::class);

        // Phase 22 — Audit log read gate (super_admin only). Not bound to
        // an Eloquent model — the underlying Spatie `Activity` model lives
        // in vendor space, so we expose a named gate and the controller
        // calls `$this->authorize('audit-logs.view')`. See
        // docs/architecture/admin-audit-templates-dashboard.md § 6.2.1.
        Gate::define('audit-logs.view', static function (User $user): bool {
            return (new AuditLogPolicy)->viewAny($user);
        });

        // Phase 21 — Admin reports policy. Not tied to an Eloquent model,
        // so it is registered as a pair of named gates that the controller
        // calls via `$this->authorize('reports.access', ReportType::Sales)`.
        //
        // PROD-ISSUE-21-1 fix: the gate names intentionally DIFFER from
        // the spatie permission keys (`reports.view` + `reports.export`).
        // spatie/laravel-permission registers a `Gate::before` callback
        // that short-circuits Gate::check to true for any user holding
        // the matching permission key — which would bypass the per-type
        // narrowing in ReportPolicy if the gates shared the permission
        // names. Renaming the gates to `reports.access` /
        // `reports.export-access` decouples the catalog permission from
        // the per-type matrix, so ReportPolicy::view() / ::export() are
        // actually invoked and the support-role narrowing takes effect.
        Gate::define('reports.access', static function (User $user, string|ReportType $type): bool {
            $resolved = $type instanceof ReportType ? $type : ReportType::tryFrom($type);

            return $resolved !== null && (new ReportPolicy)->view($user, $resolved);
        });

        Gate::define('reports.export-access', static function (User $user, string|ReportType $type): bool {
            $resolved = $type instanceof ReportType ? $type : ReportType::tryFrom($type);

            return $resolved !== null && (new ReportPolicy)->export($user, $resolved);
        });
    }

    /**
     * Configure the named rate limiters used across the API.
     *
     * Limits are tuned per CLAUDE.md § 5.7:
     *  - `api`              120/min authenticated, 60/min unauthenticated
     *  - `public`           60/min per IP (guest catalog / reference data)
     *  - `auth-strict`      5/min per IP + phone/email pair (login, OTP, reset)
     *  - `webhook`          600/min per IP (gateway burst retries)
     *  - `otp-request`      5/hour per phone (or IP fallback)
     *  - `otp-verify`       10/hour per phone (or IP fallback)
     *  - `forgot-password`  3/hour per identifier
     */
    private function configureRateLimiters(): void
    {
        // Public guest endpoints (catalog, reference data). Per CLAUDE.md
        // § 5.7 and docs/architecture/catalog.md § 1.1.
        RateLimiter::for('public', static function (Request $request): Limit {
            return Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(120)->by((string) $user->getAuthIdentifier())
                : Limit::perMinute(60)->by((string) $request->ip());
        });

        RateLimiter::for('auth-strict', static function (Request $request): Limit {
            $key = (string) ($request->input('phone') ?? $request->input('email') ?? '');

            return Limit::perMinute(5)->by($request->ip().':'.$key);
        });

        RateLimiter::for('webhook', static function (Request $request): Limit {
            return Limit::perMinute(600)->by((string) $request->ip());
        });

        RateLimiter::for('otp-request', static function (Request $request): Limit {
            $key = (string) ($request->input('phone') ?? $request->ip());

            return Limit::perHour(5)->by($key);
        });

        RateLimiter::for('otp-verify', static function (Request $request): Limit {
            $key = (string) ($request->input('phone') ?? $request->ip());

            return Limit::perHour(10)->by($key);
        });

        RateLimiter::for('forgot-password', static function (Request $request): Limit {
            $key = (string) ($request->input('email')
                ?? $request->input('phone')
                ?? $request->ip());

            return Limit::perHour(3)->by($key);
        });

        // File uploads are heavier than typical reads (5 MB × N). Per
        // docs/architecture/files.md § 1.1, override the default 120/min
        // to 30/hour per authenticated user (IP fallback for safety).
        RateLimiter::for('files-upload', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perHour(30)->by($key);
        });

        // Promo-code validation — tight limiter to defeat brute-force
        // code enumeration. Per docs/architecture/promotions.md § 6.3:
        // 10/min keyed on auth user id (IP fallback for safety even
        // though the route is auth:sanctum-gated).
        RateLimiter::for('promotions.validate', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Phase 8 — Orders. Tight per-user limits on the two write
        // endpoints. See docs/architecture/orders.md § 1.4.
        RateLimiter::for('orders.create', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('orders.cancel', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Phase 9 — Payments. Tight per-user+IP limiter on initiate so a
        // compromised token can't spam the gateway / DB. Webhook reuses
        // the shared `webhook` 600/min/IP limiter.
        // See docs/architecture/payments.md § 1.4 / § 6.4.
        RateLimiter::for('payments.initiate', static function (Request $request): Limit {
            $user = $request->user();
            $userKey = $user !== null
                ? (string) $user->getAuthIdentifier()
                : 'guest';

            return Limit::perMinute(5)
                ->by($userKey.':'.$request->ip())
                ->response(static fn () => ApiResponse::error(
                    __('payments.rate_limited'),
                    429,
                    null,
                    'ERR_RATE_LIMITED',
                ));
        });

        // Phase 10 — Maintenance request creation. 10/min/user is the
        // soft cap surfaced via the controller-side `throttle:` middleware
        // alias. See docs/architecture/machines-maintenance.md § 12.1.
        RateLimiter::for('maintenance.create', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Phase 12 — Support ticket creation. 10/min/user protects the
        // single ops staff inbox from ticket-spam. See
        // docs/architecture/support.md § 1.2 / § 1.4.
        RateLimiter::for('support.create', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(10)
                ->by($key)
                ->response(static fn () => ApiResponse::error(
                    __('support.rate_limited'),
                    429,
                    null,
                    'ERR_RATE_LIMITED',
                ));
        });

        // Phase 22 — RFQ creation (auth:sanctum, company only). 5/min/user
        // to prevent abuse while allowing legitimate business users.
        RateLimiter::for('rfq.create', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Phase 22 — Shopping-cart mutations (auth:sanctum). 30/min/user
        // keeps the cart responsive while preventing abuse.
        RateLimiter::for('cart', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(30)->by($key);
        });

        // Phase 13 — Contact form (public, no auth). Tight 5/min/IP to
        // frustrate spambots. See docs/architecture/contact.md § 1.2.
        RateLimiter::for('contact-form', static function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by((string) $request->ip())
                ->response(static fn () => ApiResponse::error(
                    __('contact.rate_limited'),
                    429,
                    null,
                    'ERR_RATE_LIMITED',
                ));
        });

        // Phase 21 — Admin report exports. Tight 10/min/user limit because
        // each call streams thousands of rows and pulls a DB connection
        // for the duration. See docs/architecture/admin-reports.md § 6.8.
        RateLimiter::for('reports-export', static function (Request $request): Limit {
            $user = $request->user();
            $key = $user !== null
                ? (string) $user->getAuthIdentifier()
                : (string) $request->ip();

            return Limit::perMinute(10)
                ->by($key)
                ->response(static fn () => ApiResponse::error(
                    __('admin.reports.rate_limited'),
                    429,
                    null,
                    'ERR_RATE_LIMITED',
                ));
        });
    }
}
