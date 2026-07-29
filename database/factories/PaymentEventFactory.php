<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
final class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => 'webhook',
            'source' => 'stub',
            'gateway_event_id' => 'evt_'.bin2hex(random_bytes(8)),
            'payload' => ['status' => 'success', 'amount' => '100.00'],
            'signature_valid' => true,
            'processed_at' => now(),
            'created_at' => now(),
        ];
    }

    public function webhook(): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => 'webhook',
            'signature_valid' => true,
        ]);
    }

    public function manual(): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => 'manual',
            'source' => 'admin',
            'signature_valid' => null,
        ]);
    }

    public function system(): self
    {
        return $this->state(fn (array $attrs): array => [
            'type' => 'system',
            'source' => 'system',
            'signature_valid' => null,
        ]);
    }

    public function forPayment(Payment $payment): self
    {
        return $this->state(fn (array $attrs): array => [
            'payment_id' => $payment->id,
        ]);
    }
}
