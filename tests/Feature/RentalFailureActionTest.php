<?php

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('machine_rental_payments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status');
    });
});

it('removes only the action for a failed notification whose own payment is now paid', function () {
    DB::table('machine_rental_payments')->insert([
        ['id' => 10, 'user_id' => 5, 'status' => 'paid'],
        ['id' => 11, 'user_id' => 5, 'status' => 'failed'],
    ]);

    $notification = new Notification([
        'user_id' => 5,
        'type' => 'rental.payment_failed',
        'data' => ['payment_id' => 10, 'customer_name' => 'Aya'],
        'action_url' => 'https://example.com/payment',
        'title' => ['en' => 'Payment failed'],
        'body' => ['en' => 'Please review payment'],
    ]);

    expect($notification->action_url)->toBeNull();
    expect((new GenericNotificationMail($notification))->render())->not->toContain('Review Payment Details</a>');

    $notification->data = ['payment_id' => 11];
    expect($notification->action_url)->toBe('https://example.com/payment');

    $notification->data = ['payment_id' => 10];
    $notification->user_id = 6;
    expect($notification->action_url)->toBe('https://example.com/payment');

    $notification->user_id = 5;
    $notification->type = 'rental.payment_captured';
    expect($notification->action_url)->toBe('https://example.com/payment');
});
