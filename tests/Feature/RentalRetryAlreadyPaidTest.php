<?php

use App\Models\MachineRentalSchedule;
use App\Services\Rentals\MachineRentalPaymentService;

uses(Tests\TestCase::class);

it('does not attempt another charge after a successful rental payment', function () {
    $schedule = new MachineRentalSchedule([
        'last_payment_at' => today()->toDateString(),
        'next_payment_due_at' => today()->addMonth()->toDateString(),
    ]);

    $result = (new MachineRentalPaymentService)->retryPayment($schedule);

    expect($result['status'])->toBe('already_paid')
        ->and($result['next_due_date'])->toBe(today()->addMonth()->toDateString());
});

it('allows a new due installment to reach normal payment validation', function () {
    $schedule = new MachineRentalSchedule([
        'last_payment_at' => today()->subMonth()->toDateString(),
        'next_payment_due_at' => today()->toDateString(),
    ]);
    $schedule->setRelation('paymentCard', null);

    expect((new MachineRentalPaymentService)->retryPayment($schedule)['status'])->toBe('failed');
});
