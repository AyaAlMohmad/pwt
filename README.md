# PWT Digital Platform — API Backend

Laravel 11 REST API backend for the PWT Digital Platform (machine rental & maintenance).

## Stack

- **PHP** 8.2+ / **Laravel** 11
- **Auth**: Laravel Sanctum (customer + admin guards)
- **Authorization**: spatie/laravel-permission · **Audit**: spatie/laravel-activitylog · **i18n**: spatie/laravel-translatable
- **API Docs**: l5-swagger (Swagger UI)
- **Payments**: Stripe (+ PayFort driver stub)
- **Push notifications**: Firebase (kreait/laravel-firebase)
- **Testing**: Pest · **Static analysis**: Larastan (PHPStan) · **Style**: Pint
- **Frontend build**: Vite + Tailwind (admin/web views)

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve        # http://127.0.0.1:8000
```

- Swagger UI: `http://127.0.0.1:8000/api/documentation`
- MySQL database name: `pwt`

Queue worker (notifications, etc.):

```bash
php artisan queue:work   # see docs/pwt-queue-and-supervisor-deployment-guide.md for production supervisor setup
```

## Project layout

- `routes/api.php` — customer API (`/api/v1`)
- `routes/api_admin.php` — admin API (`/api/v1/admin`)
- `app/OpenApi` — Swagger annotations
- `database/` — migrations, seeders, and ad-hoc ops/maintenance scripts
- `docs/postman` — Postman collection · `docs/verification` — delivery/verification reports
- `lang/` — translations

## Useful commands

```bash
./vendor/bin/pest        # tests
./vendor/bin/phpstan     # static analysis (phpstan.neon)
./vendor/bin/pint        # code style
php artisan l5-swagger:generate   # regenerate API docs
```

## Payments

`PAYMENT_DRIVER` selects the gateway (`stripe` or `payfort` stub). When PayFort credentials are available, set `PAYFORT_MERCHANT_ID`, `PAYFORT_ACCESS_CODE`, `PAYFORT_SHA_REQUEST_PHRASE`, `PAYFORT_SHA_RESPONSE_PHRASE`, `PAYFORT_WEBHOOK_SECRET` in `.env`.
