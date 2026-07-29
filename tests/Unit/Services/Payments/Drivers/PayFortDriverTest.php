<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\Drivers\PayFortDriver;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| PayFortDriver
|--------------------------------------------------------------------------
|
| The PayFort driver is a Phase-17 wiring stub. Real outbound HTTP and
| canonical signing are deferred. The tests below pin the safety
| contract: misconfiguration must FAIL LOUD, never silently succeed.
*/

beforeEach(function (): void {
    config([
        'payment.payfort.access_code' => null,
        'payment.payfort.webhook_secret' => null,
        'payment.payfort.sha_response_phrase' => null,
        'payment.payfort.signature_header' => 'x-payfort-signature',
    ]);

    $this->driver = new PayFortDriver;
});

describe('PayFortDriver::name()', function (): void {
    it('returns "payfort"', function (): void {
        expect($this->driver->name())->toBe('payfort');
    });
});

describe('PayFortDriver::initiate()', function (): void {
    it('throws RuntimeException when credentials are missing', function (): void {
        // We use the contract-level expectation: the driver MUST refuse
        // to operate without credentials. Whether that surfaces as
        // "credentials missing" or "outbound HTTP not implemented", a
        // RuntimeException is the safe failure mode.
        $payment = new Payment;
        $order = new Order;

        expect(fn () => $this->driver->initiate($payment, $order))
            ->toThrow(RuntimeException::class);
    });
});

describe('PayFortDriver::verifyWebhookSignature()', function (): void {
    it('returns false when no webhook_secret is configured (fail closed)', function (): void {
        $result = $this->driver->verifyWebhookSignature('body', [
            'x-payfort-signature' => 'whatever',
        ]);
        expect($result)->toBeFalse();
    });

    it('returns false when the signature header is missing', function (): void {
        config([
            'payment.payfort.webhook_secret' => 'secret',
            'payment.payfort.sha_response_phrase' => 'phrase',
        ]);

        $result = $this->driver->verifyWebhookSignature('body', []);
        expect($result)->toBeFalse();
    });

    it('returns false by default until Phase 17 wires real HMAC verification', function (): void {
        config([
            'payment.payfort.webhook_secret' => 'secret',
            'payment.payfort.sha_response_phrase' => 'phrase',
        ]);

        $result = $this->driver->verifyWebhookSignature('body', [
            'x-payfort-signature' => 'pending-impl',
        ]);
        expect($result)->toBeFalse();
    });
});

describe('PayFortDriver::parseWebhookPayload()', function (): void {
    it('maps PayFort canonical success (status=14 + response_code=14*) → Captured', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'fort_id' => 'F123',
            'merchant_reference' => 'ref-abc',
            'status' => '14',
            'response_code' => '14000',
            'amount' => '52500', // minor units (525.00)
            'currency' => 'AED',
        ]);

        expect($result->newStatus)->toBe(PaymentStatus::Captured)
            ->and($result->gatewayTransactionId)->toBe('F123')
            ->and($result->gatewayReference)->toBe('ref-abc')
            ->and($result->amount)->toBe('525.00') // bcdiv 52500 / 100
            ->and($result->currency)->toBe('AED');
    });

    it('maps status=02 → Authorized', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'fort_id' => 'F123',
            'status' => '02',
            'response_code' => '02000',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Authorized);
    });

    it('maps an unknown / empty status → Failed (fail-safe default)', function (): void {
        $result = $this->driver->parseWebhookPayload([
            'fort_id' => 'F123',
            'status' => '99',
            'response_code' => '99000',
            'response_message' => 'Something broke',
        ]);
        expect($result->newStatus)->toBe(PaymentStatus::Failed)
            ->and($result->failureReason)->toBe('Something broke');
    });
});
