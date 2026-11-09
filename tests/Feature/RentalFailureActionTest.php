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
        $table->unsignedBigInteger('machine_rental_schedule_id')->nullable();
        $table->unsignedBigInteger('machine_id')->nullable();
        $table->date('due_date')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('paid_at')->nullable();
    });
});

it('removes the failed action when a different attempt pays the same installment', function () {
    DB::table('machine_rental_payments')->insert([
        ['id' => 32, 'user_id' => 5, 'status' => 'failed', 'machine_rental_schedule_id' => 3, 'machine_id' => 2017, 'due_date' => '2026-11-10'],
        ['id' => 33, 'user_id' => 5, 'status' => 'paid', 'machine_rental_schedule_id' => 3, 'machine_id' => 2017, 'due_date' => '2026-11-10'],
        ['id' => 34, 'user_id' => 5, 'status' => 'failed', 'machine_rental_schedule_id' => 3, 'machine_id' => 2017, 'due_date' => '2026-12-10'],
        ['id' => 35, 'user_id' => 5, 'status' => 'failed', 'machine_rental_schedule_id' => 4, 'machine_id' => 2018, 'due_date' => '2026-11-10'],
    ]);
    $notification = new Notification([
        'user_id' => 5,
        'type' => 'rental.payment_failed',
        'data' => ['payment_id' => 32],
        'action_url' => 'https://example.com/payment',
    ]);

    DB::table('machine_rental_payments')->where('id', 32)->update(['created_at' => '2026-11-10 10:00:00']);
    DB::table('machine_rental_payments')->where('id', 33)->update(['paid_at' => '2026-11-10 09:00:00']);
    expect($notification->action_url)->toBe('https://example.com/payment');

    DB::table('machine_rental_payments')->where('id', 33)->update(['paid_at' => '2026-11-10 10:01:00']);
    expect($notification->action_url)->toBeNull();

    foreach ([34, 35] as $paymentId) {
        $notification->data = ['payment_id' => $paymentId];
        expect($notification->action_url)->toBe('https://example.com/payment');
    }
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
