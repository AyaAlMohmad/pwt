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
use Database\Seeders\CountriesAndRegionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
|--------------------------------------------------------------------------
| GET /api/v1/payments/{id}/status
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/payments.md § 1.1, § 3.3, § 4.3.
| Owner-only. 403 on ownership failure (not 404). Never exposes
| gateway_transaction_id, gateway_reference, or payment_url.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CountriesAndRegionsSeeder::class);

    config(['payment.default' => 'stub']);
    $this->uae = Country::query()->where('code', 'ae')->firstOrFail();
});

/**
 * @return array{user: User, token: string, payment: Payment}
 */
function makePaymentForUser(): array
{
    $uae = Country::query()->where('code', 'ae')->firstOrFail();
    $user = User::factory()->customer()->create();
    $token = $user->createToken('mobile', ['*'])->plainTextToken;
    $address = Address::factory()->forUser($user)->forCountry($uae)->create();
    $order = Order::factory()->forUser($user)->forAddress($address)->state([
        'status' => OrderStatus::Pending->value,
        'currency' => 'AED',
        'total_amount' => '525.00',
        'payment_method' => PaymentMethod::Card->value,
    ])->create();
    $payment = Payment::factory()->forOrder($order)->initiated()->create();

    return ['user' => $user, 'token' => $token, 'payment' => $payment];
}

describe('GET /api/v1/payments/{id}/status — owner read', function (): void {
    it('returns 200 with the safe field allowlist', function (): void {
        ['token' => $token, 'payment' => $payment] = makePaymentForUser();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/payments/{$payment->id}/status");

        $response->assertOk()
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'payment_id', 'public_id', 'order_id', 'status',
                    'payment_method', 'gateway', 'amount', 'currency',
                    'captured_at', 'failed_at', 'cancelled_at',
                    'failure_reason', 'created_at', 'updated_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.status', PaymentStatus::Initiated->value)
            // payment_url and expires_at suppressed on status endpoint.
            ->assertJsonPath('data.payment_url', null)
            ->assertJsonPath('data.expires_at', null);
    });

    it('never exposes gateway_transaction_id or gateway_reference', function (): void {
        ['token' => $token, 'payment' => $payment] = makePaymentForUser();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/payments/{$payment->id}/status");

        $response->assertOk()
            ->assertJsonMissingPath('data.gateway_transaction_id')
            ->assertJsonMissingPath('data.gateway_reference')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.user_id');
    });
});

describe('GET /api/v1/payments/{id}/status — ownership', function (): void {
    it('returns 403 ERR_FORBIDDEN when a different customer tries to read the payment', function (): void {
        ['payment' => $payment] = makePaymentForUser();

        $stranger = User::factory()->customer()->create();
        $token = $stranger->createToken('mobile', ['*'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/payments/{$payment->id}/status");

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ERR_FORBIDDEN');
    });
});

describe('GET /api/v1/payments/{id}/status — not found', function (): void {
    it('returns 404 for a non-existent payment id', function (): void {
        ['token' => $token] = makePaymentForUser();

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/payments/99999999/status');

        $response->assertStatus(404)
            ->assertJsonPath('code', 'ERR_NOT_FOUND');
    });
});

describe('GET /api/v1/payments/{id}/status — auth', function (): void {
    it('returns 401 ERR_UNAUTHENTICATED with no token', function (): void {
        ['payment' => $payment] = makePaymentForUser();

        $response = $this->getJson("/api/v1/payments/{$payment->id}/status");

        $response->assertStatus(401)
            ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
    });
});
