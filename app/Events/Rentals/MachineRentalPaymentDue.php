<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\BaseDomainEvent;

final class MachineRentalPaymentDue extends BaseDomainEvent
{
    public function __construct(
        public readonly int $scheduleId,
        public readonly int $machineId,
        public readonly int $userId,
        public readonly string $dueDate,
        public readonly string $userName = '',
        public readonly string $userEmail = '',
        public readonly string $machineName = '',
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public function templateKey(): string
    {
        return 'rental.payment_due';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'schedule_id' => $this->scheduleId,
            'machine_id' => $this->machineId,
            'user_id' => $this->userId,
            'due_date' => $this->dueDate,
            'user_name' => $this->userName,
            'user_email' => $this->userEmail,
            'machine_name' => $this->machineName,
        ];
    }
}
