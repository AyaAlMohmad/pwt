<?php

declare(strict_types=1);

use App\Services\Payments\Drivers\PayFortDriver;
use App\Services\Payments\Drivers\StripeDriver;
use App\Services\Payments\Drivers\StubDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | Controls which gateway handles online card / Apple Pay / Google Pay
    | flows. COD is always handled directly by PaymentService and does not
    | call into a driver.
    |
    | Supported: "stub" (dev / testing), "payfort" (production), "stripe".
    |
    */
    'default' => env('PAYMENT_DRIVER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Initiated TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | How long an `initiated` payment URL is considered active. After this
    | window expires, a new POST /payments/initiate on the same order may
    | proceed even if a previous `initiated` row still exists.
    |
    */
    'initiated_ttl_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Idempotency window (hours)
    |--------------------------------------------------------------------------
    |
    | Window during which the same `Idempotency-Key` for the same user
    | replays the original Payment row (200 instead of 201). Mirrors the
    | Orders module convention.
    |
    */
    'idempotency_window_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Driver map
    |--------------------------------------------------------------------------
    |
    | Class names per driver key. AppServiceProvider binds the
    | PaymentGateway contract to the active driver class.
    |
    */
    'drivers' => [
        'stub' => StubDriver::class,
        'payfort' => PayFortDriver::class,
        'stripe' => StripeDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | PayFort settings (production)
    |--------------------------------------------------------------------------
    |
    | All optional; the PayFortDriver throws on initiate() unless
    | access_code is non-empty so an accidental selection without
    | credentials surfaces clearly.
    |
    */
    'payfort' => [
        'access_code' => env('PAYFORT_ACCESS_CODE'),
        'merchant_identifier' => env('PAYFORT_MERCHANT_IDENTIFIER'),
        'sha_request_phrase' => env('PAYFORT_SHA_REQUEST_PHRASE'),
        'sha_response_phrase' => env('PAYFORT_SHA_RESPONSE_PHRASE'),
        'webhook_secret' => env('PAYFORT_WEBHOOK_SECRET'),
        'mode' => env('PAYFORT_MODE', 'test'),
        'base_url' => env('PAYFORT_BASE_URL', 'https://sbpaymentservices.payfort.com'),
        'return_url' => env('PAYFORT_RETURN_URL'),
        'signature_header' => 'x-payfort-signature',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stub driver settings (development)
    |--------------------------------------------------------------------------
    */
    'stub' => [
        'signature_header' => 'x-stub-signature',
        'signature_value' => env('STUB_PAYMENT_SIGNATURE', 'stub-signature-ok'),
        'checkout_base_url' => env('STUB_PAYMENT_CHECKOUT_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe settings
    |--------------------------------------------------------------------------
    |
    | secret_key       – Stripe Secret Key (test or live)
    | publishable_key  – Stripe Publishable Key (used by frontend)
    | webhook_secret   – Webhook endpoint secret for signature verification
    | return_url       – Full URL the customer is redirected to after success
    | cancel_url       – Full URL the customer is redirected to after cancel
    |
    */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'return_url' => env('STRIPE_RETURN_URL'),
        'cancel_url' => env('STRIPE_CANCEL_URL'),
        'signature_header' => 'stripe-signature',
    ],
];
