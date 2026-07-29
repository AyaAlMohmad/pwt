<?php

declare(strict_types=1);

namespace App\Events\Maintenance;

use App\Events\BaseDomainEvent;
use App\Models\MaintenanceRequest;

final class MaintenanceRequestAssigned extends BaseDomainEvent
{
    public function __construct(
        public readonly int $requestId,
        public readonly int $userId,
        ?int $actorUserId = null,
        public readonly ?string $note = null,
    ) {
        parent::__construct($actorUserId);
    }

    public static function fromModel(MaintenanceRequest $request, ?int $actorUserId = null, ?string $note = null): self
    {
        return new self($request->id, $request->user_id, $actorUserId, $note);
    }

    public function templateKey(): string
    {
        return 'maintenance.assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'maintenance_request_id' => $this->requestId,
            'user_id' => $this->userId,
            'note' => $this->note,
        ];
    }
}
