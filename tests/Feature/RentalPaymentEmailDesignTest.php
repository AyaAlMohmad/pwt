<?php

use App\Mail\GenericNotificationMail;
use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class);

it('renders the rental failure design with safe customer data and the existing action link', function () {
    $notification = new Notification([
        'type' => 'rental.payment_failed',
        'title' => ['en' => 'Payment unsuccessful'],
        'body' => ['en' => 'Payment failed'],
        'action_url' => 'https://example.com/notifications/payment',
        'data' => [
            'customer_name' => 'Aya <script>alert(1)</script>',
            'machine_name' => 'Water dispenser',
            'machine_id' => 42,
            'amount' => '150.50',
            'currency' => 'AED',
            'customer_reason' => 'Your card was declined.',
            'reason' => 'private_gateway_debug',
        ],
    ]);

    $mail = new GenericNotificationMail($notification);
    $html = $mail->render();

    expect($html)->toContain('PURE WATER TECHNOLOGY', 'Rental Payment Unsuccessful', 'Payment Summary', 'AED 150.50', 'Water dispenser - #42', 'Your card was declined.', 'https://example.com/notifications/payment', 'Review Payment Details')
        ->not->toContain('private_gateway_debug', '<script>');
});

it('renders missing rental details safely without querying a recipient', function () {
    $notification = new Notification([
        'type' => 'rental.payment_failed',
        'title' => ['en' => 'Payment unsuccessful'],
        'body' => ['en' => 'Payment failed'],
        'data' => [],
    ]);
    $notification->setRelation('user', new User(['name' => 'Aya']));

    expect((new GenericNotificationMail($notification))->render())
        ->toContain('Dear Aya,', 'Please review your payment method.')
        ->not->toContain('Review Payment Details</a>');
});

it('keeps other notification emails on the existing template', function () {
    $notification = new Notification([
        'type' => 'order.created',
        'title' => ['en' => 'Order received'],
        'body' => ['en' => 'Thank you for your order'],
    ]);

    expect((new GenericNotificationMail($notification))->content()->view)->toBe('emails.notification');
});
