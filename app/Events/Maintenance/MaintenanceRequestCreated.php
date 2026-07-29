<?php

declare(strict_types=1);

namespace App\Events\Maintenance;

use App\Events\BaseDomainEvent;
use App\Models\MaintenanceRequest;

final class MaintenanceRequestCreated extends BaseDomainEvent
{
    public function __construct(
        public readonly int $requestId,
        public readonly int $userId,
        public readonly int $machineId,
        ?int $actorUserId = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(MaintenanceRequest $request): self
    {
        return new self(
            requestId: $request->id,
            userId: $request->user_id,
            machineId: $request->machine_id,
            actorUserId: $request->user_id,
        );
    }

    public function templateKey(): string
    {
        return 'maintenance.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'maintenance_request_id' => $this->requestId,
            'machine_id' => $this->machineId,
            'user_id' => $this->userId,
        ];
    }
}
