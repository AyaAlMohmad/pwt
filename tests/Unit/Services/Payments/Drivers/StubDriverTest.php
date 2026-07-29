<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Country;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Drivers\StubDriver;
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| StubDriver
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/payments.md § 8.3.
| Synthetic checkout URL, deterministic signature header, status mapper
| mirroring PayFort's canonical fields.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    config(['payment.default' => 'stub']);

    $this->driver = new StubDriver;

    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

function stubMakeOrder(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $order = Order::factory()->forUser($user)->forAddress($address)->state([
        'status' => OrderStatus::Pending->value,
        'currency' => 'AED',
        'total_amount' => '525.00',
        'payment_method' => PaymentMethod::Card->value,
    ])->create();
    $payment = Payment::factory()->forOrder($order)->initiated()->create();

    return [$payment, $order];
}

describe('StubDriver::name()', function (): void {
    it('returns "stub"', function (): void {
        expect($this->driver->name())->toBe('stub');
    });
});

describe('StubDriver::initiate()', function (): void {
    it('returns a deterministic-shaped payment URL with a stub_ correlator id', function (): void {
        [$payment, $order] = stubMakeOrder();

        $result = $this->driver->initiate($payment, $order);

        expect($result->clientPaymentUrl)->toBeString()
            ->and($result->clientPaymentUrl)->toContain('?session=')
            ->and($result->gatewayTransactionId)->toStartWith('stub_')
            ->and($result->gatewayReference)->toBe($payment->public_id)
            ->and($result->expiresAt->isFuture())->toBeTrue();

        // Raw forensic blob contains key driver metadata.
        expect($result->raw)->toHaveKeys(['driver', 'session', 'amount', 'currency']);
    });
});

describe('StubDriver::verifyWebhookSignature()', function (): void {
    it('accepts only the canonical X-Stub-Signature value', function (): void {
        $ok = $this->driver->verifyWebhookSignature('{}', [
            'x-stub-signature' => 'stub-signature-ok',
        ]);
        expect($ok)->toBeTrue();
    });

    it('rejects when the header is missing', function (): void {
        expect($this->driver->verifyWebhookSignature('{}', []))->toBeFalse();
    });

    it('rejects when the header value is wrong', function (): void {
        expect($this->driver->verifyWebhookSignature('{}', [
            'x-stub-signature' => 'wrong',
        ]))->toBeFalse();
    });

    it('rejects an empty header value', function (): void {
        expect($this->driver->verifyWebhookSignature('{}', [
            'x-stub-signature' => '',
        ]))->toBeFalse();
    });
});

describe('StubDriver::parseWebhookPayload()', function (): void {
    it('maps `status=success` to PaymentStatus::Captured', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'transaction_id' => 'stub_abc',
            'status' => 'success',
            'amount' => '100.00',
            'currency' => 'AED',
            'event_id' => 'evt_1',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Captured)
            ->and($result->gatewayTransactionId)->toBe('stub_abc')
            ->and($result->amount)->toBe('100.00')
            ->and($result->currency)->toBe('AED')
            ->and($result->eventId)->toBe('evt_1');
    });

    it('maps `status=failed` to PaymentStatus::Failed', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'transaction_id' => 'stub_x',
            'status' => 'failed',
            'failure_reason' => 'declined',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Failed)
            ->and($result->failureReason)->toBe('declined');
    });

    it('maps `status=cancelled` to PaymentStatus::Cancelled', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'transaction_id' => 'stub_x',
            'status' => 'cancelled',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Cancelled);
    });

    it('maps `status=authorized` to PaymentStatus::Authorized', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'transaction_id' => 'stub_x',
            'status' => 'authorized',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Authorized);
    });

    it('falls through to Failed for an unrecognised status', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'transaction_id' => 'stub_x',
            'status' => 'martian-state',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Failed);
    });

    it('accepts the alternate `gateway_transaction_id` field', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'gateway_transaction_id' => 'alt_txn',
            'status' => 'success',
        ]);
        expect($result->gatewayTransactionId)->toBe('alt_txn');
    });
});
