<?php

declare(strict_types=1);

namespace App\Events\Payments;

use App\Events\BaseDomainEvent;
use App\Models\Payment;

final class PaymentCaptured extends BaseDomainEvent
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $userId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(Payment $payment): self
    {
        return new self($payment->id, $payment->order_id, $payment->user_id);
    }

    public function templateKey(): string
    {
        return 'payment.captured';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
        ];
    }
}
