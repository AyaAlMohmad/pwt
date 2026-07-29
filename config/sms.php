<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\Sms\Drivers\AwsSnsDriver;
use App\Services\Notifications\Channels\Sms\Drivers\FirebaseSmsDriver;
use App\Services\Notifications\Channels\Sms\Drivers\StubSmsDriver;
use App\Services\Notifications\Channels\Sms\Drivers\TwilioDriver;
use App\Services\Notifications\Channels\Sms\Drivers\UnifonicDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Default SMS Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the SMS driver used to dispatch transactional
    | messages (OTP, reset codes). The `stub` driver writes to
    | `storage/logs/otp.log` and is the default for local / testing /
    | staging envs. Production deployments override `SMS_DRIVER` to a
    | real provider (Twilio, Unifonic).
    |
    */
    'default' => env('SMS_DRIVER', 'stub'),

    /*
    |--------------------------------------------------------------------------
    | Driver Map
    |--------------------------------------------------------------------------
    |
    | Driver keys map to a concrete implementation of
    | `App\Services\Notifications\Channels\Sms\SmsDriver`.
    |
    */
    'drivers' => [
        'stub'         => StubSmsDriver::class,
        'twilio'       => TwilioDriver::class,
        'unifonic'     => UnifonicDriver::class,
        'aws_sns'      => AwsSnsDriver::class,
        'firebase_sms' => FirebaseSmsDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Sender ID
    |--------------------------------------------------------------------------
    */
    'sender' => env('SMS_SENDER', 'PWT'),

    /*
    |--------------------------------------------------------------------------
    | Test Mode (development / pre-SMS activation)
    |--------------------------------------------------------------------------
    |
    | When enabled, OTP codes are fixed to `test_code` for all users.
    | This lets frontend teams test registration flows without waiting
    | for actual SMS delivery. Disable (set false) when SMS is live.
    |
    */
    'test_mode' => env('SMS_TEST_MODE', false),
    'test_code' => env('SMS_TEST_CODE', '123456'),
];
