<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\BaseDomainEvent;

final class MachineRentalPaymentFailed extends BaseDomainEvent
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $scheduleId,
        public readonly int $machineId,
        public readonly int $userId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $reason = null,
        public readonly string $customerReason = '',
        public readonly string $customerName = '',
        public readonly string $customerEmail = '',
        public readonly string $machineName = '',
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'rental.payment_failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'schedule_id' => $this->scheduleId,
            'machine_id' => $this->machineId,
            'user_id' => $this->userId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'customer_reason' => $this->customerReason,
            'customer_name' => $this->customerName,
            'customer_email' => $this->customerEmail,
            'machine_name' => $this->machineName,
        ];
    }
}
