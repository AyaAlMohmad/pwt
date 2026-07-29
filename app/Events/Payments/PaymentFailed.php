<?php

declare(strict_types=1);

namespace App\Events\Payments;

use App\Events\BaseDomainEvent;
use App\Models\Payment;

final class PaymentFailed extends BaseDomainEvent
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly ?string $reason = null,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(Payment $payment, ?string $reason = null): self
    {
        return new self($payment->id, $payment->order_id, $payment->user_id, $reason);
    }

    public function templateKey(): string
    {
        return 'payment.failed';
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
            'reason' => $this->reason,
        ];
    }
}
