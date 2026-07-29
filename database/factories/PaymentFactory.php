<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'order_id' => Order::factory(),
            'user_id' => User::factory(),
            'payment_method' => PaymentMethod::Card->value,
            'gateway' => 'stub',
            'gateway_transaction_id' => 'stub_'.bin2hex(random_bytes(8)),
            'gateway_reference' => null,
            'amount' => '100.00',
            'currency' => 'AED',
            'status' => PaymentStatus::Initiated->value,
            'failure_reason' => null,
            'client_payment_url' => 'http://127.0.0.1:8000/stub-payment-checkout?session='.Str::uuid(),
            'idempotency_key' => null,
            'authorized_at' => null,
            'captured_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function initiated(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => PaymentStatus::Initiated->value,
            'captured_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function captured(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => PaymentStatus::Captured->value,
            'captured_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => PaymentStatus::Failed->value,
            'failed_at' => now(),
            'failure_reason' => 'Card declined',
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => PaymentStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * COD payment — created directly in `captured` with gateway=`cod`.
     */
    public function cod(): self
    {
        return $this->state(fn (array $attrs): array => [
            'payment_method' => PaymentMethod::Cod->value,
            'gateway' => 'cod',
            'gateway_transaction_id' => 'cod_'.bin2hex(random_bytes(8)),
            'client_payment_url' => null,
            'status' => PaymentStatus::Captured->value,
            'captured_at' => now(),
        ]);
    }

    public function forOrder(Order $order): self
    {
        return $this->state(fn (array $attrs): array => [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'amount' => (string) $order->total_amount,
            'currency' => $order->currency,
            'payment_method' => $order->payment_method->value,
        ]);
    }
}
