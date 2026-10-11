<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenanceLogStatus;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Events\Maintenance\MaintenanceRequestAssigned;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use App\Models\User;
use App\Services\Maintenance\MaintenanceRequestService;
use App\Services\Maintenance\MaintenanceScheduler;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin-side maintenance orchestrator.
 *
 * Cross-cutting:
 *  - Every transition still flows through Phase 10's
 *    {@see MaintenanceRequestService::transitionTo()} so the state
 *    machine has a single source of truth.
 *  - All write methods wrap in `DB::transaction` + `lockForUpdate()`
 *    for race-safe writes.
 *  - Every successful admin action emits a Spatie ActivityLog entry
 *    under the `admin.maintenance` log name.
 *
 * See docs/architecture/admin-machines-maintenance.md § 10.2.
 */
final class AdminMaintenanceService
{
    /**
     * Whitelisted sort columns -> actual DB columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
        'preferred_date' => 'preferred_date',
    ];

    public function __construct(
        private readonly MaintenanceRequestService $requests,
        private readonly MaintenanceScheduler $scheduler,
    ) {}

    /**
     * Paginated list across ALL maintenance requests.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, MaintenanceRequest>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<MaintenanceRequest> $query */
        $query = $includeTrashed
            ? MaintenanceRequest::query()->withTrashed()
            : MaintenanceRequest::query();

        $query->with([
            'user:id,name,email,phone,phone_country_code',
            'machine:id,public_id,serial_number,product_id,country_code,is_active,next_maintenance_due_at',
            'machine.product:id,code,name',
            'machine.maintenanceLogs:id,machine_id,status,completed_at',
            'assignedTo:id,name',
        ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Full-detail load with withTrashed().
     */
    public function find(int $id): MaintenanceRequest
    {
        /** @var MaintenanceRequest $request */
        $request = MaintenanceRequest::query()
            ->withTrashed()
            ->with([
                'user:id,name,email,phone,phone_country_code,locale,is_active,deleted_at',
                'machine.product:id,code',
                'machine.address.country',
                'assignedTo:id,name',
                'cancelledBy:id,name',
                'media',
                'statusHistory.actor:id,name',
            ])
            ->findOrFail($id);

        // Eager load roles on the relevant actor users so the resource
        // can surface the admin's role without an N+1 storm.
        $userIds = collect([
            $request->assignedTo?->id,
            $request->cancelledBy?->id,
        ])
            ->merge(
                $request->statusHistory
                    ->map(static fn (MaintenanceStatusHistory $h) => $h->actor?->id),
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($userIds !== []) {
            $users = User::query()
                ->whereIn('id', $userIds)
                ->with('roles:id,name')
                ->get()
                ->keyBy('id');

            if ($request->assignedTo !== null && isset($users[$request->assignedTo->id])) {
                $request->setRelation('assignedTo', $users[$request->assignedTo->id]);
            }
            if ($request->cancelledBy !== null && isset($users[$request->cancelledBy->id])) {
                $request->setRelation('cancelledBy', $users[$request->cancelledBy->id]);
            }
            foreach ($request->statusHistory as $row) {
                $actor = $row->actor;
                if ($actor !== null && isset($users[$actor->id])) {
                    $row->setRelation('actor', $users[$actor->id]);
                }
            }
        }

        return $request;
    }

    /**
     * Submit a maintenance request on behalf of a customer.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitOnBehalf(array $payload, User $actor, ?string $ip = null): MaintenanceRequest
    {
        $request = DB::transaction(function () use ($payload, $actor): MaintenanceRequest {
            $customerId = (int) $payload['customer_id'];
            $machineId = (int) $payload['machine_id'];

            // Defence-in-depth: re-load the machine with a row lock and
            // re-assert it belongs to the supplied customer.
            $machine = Machine::query()
                ->where('id', $machineId)
                ->where('user_id', $customerId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if ($machine === null) {
                // Surfaces as 422 in the controller via ValidationException
                // but inside the transaction we trust the Form Request
                // already passed; this is purely defensive.
                throw new InvalidMaintenanceTransitionException(
                    __('admin.maintenance.machine_not_owned'),
                    422,
                    'ERR_MACHINE_NOT_OWNED',
                );
            }

            $issueType = MaintenanceIssueType::from((string) $payload['issue_type']);
            $priorityRaw = isset($payload['priority']) && is_string($payload['priority']) && $payload['priority'] !== ''
                ? $payload['priority']
                : null;
            $priority = $priorityRaw !== null
                ? (MaintenancePriority::tryFrom(strtolower($priorityRaw)) ?? MaintenancePriority::Medium)
                : MaintenancePriority::Medium;

            $preferred = isset($payload['preferred_date']) && is_string($payload['preferred_date']) && $payload['preferred_date'] !== ''
                ? Carbon::parse($payload['preferred_date'])->toDateString()
                : null;

            /** @var MaintenanceRequest $request */
            $request = MaintenanceRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $customerId,
                'machine_id' => $machine->id,
                'issue_type' => $issueType->value,
                'description' => (string) $payload['description'],
                'internal_notes' => isset($payload['internal_notes']) && is_string($payload['internal_notes'])
                    ? $payload['internal_notes']
                    : null,
                'status' => MaintenanceStatus::New->value,
                'priority' => $priority->value,
                'preferred_date' => $preferred,
            ]);

            // Initial history row — actor is the submitting admin.
            MaintenanceStatusHistory::query()->create([
                'maintenance_request_id' => $request->id,
                'from_status' => null,
                'to_status' => MaintenanceStatus::New->value,
                'actor_type' => MaintenanceActorType::Admin->value,
                'actor_id' => $actor->getAuthIdentifier(),
                'note' => null,
            ]);

            $requestId = (int) $request->id;
            $userId = (int) $customerId;
            $machineId = (int) $machine->id;
            $actorIdRaw = $actor->getAuthIdentifier();
            $actorId = is_numeric($actorIdRaw) ? (int) $actorIdRaw : $userId;
            DB::afterCommit(static function () use ($requestId, $userId, $machineId, $actorId): void {
                event(new MaintenanceRequestCreated($requestId, $userId, $machineId, $actorId));
            });

            // If admin supplied an assignee, transition new -> assigned
            // immediately within the same transaction.
            if (isset($payload['assigned_to_user_id']) && is_numeric($payload['assigned_to_user_id'])) {
                $request->assigned_to_user_id = (int) $payload['assigned_to_user_id'];
                $request->save();

                $note = isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null;

                $request = $this->requests->transitionTo(
                    r: $request,
                    toStatus: MaintenanceStatus::Assigned,
                    actor: $actor,
                    actorType: MaintenanceActorType::Admin,
                    payload: ['note' => $note],
                );
            }

            return $request;
        });

        $this->logActivity(
            event: 'admin.maintenance.created',
            description: 'Admin submitted maintenance request on behalf of customer',
            request: $request,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'customer_id' => $request->user_id,
                'machine_id' => $request->machine_id,
                'priority' => $request->priority->value,
            ],
        );

        return $this->find((int) $request->id);
    }

    /**
     * Submit maintenance requests for multiple machines in one batch.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, MaintenanceRequest>
     */
    public function submitBatchOnBehalf(array $payload, User $actor, ?string $ip = null): array
    {
        $machineIds = isset($payload['machine_ids']) && is_array($payload['machine_ids'])
            ? array_map(static fn ($id): int => (int) $id, $payload['machine_ids'])
            : [];

        if ($machineIds === []) {
            throw new InvalidMaintenanceTransitionException(
                __('admin.maintenance.no_machines'),
                422,
                'ERR_NO_MACHINES',
            );
        }

        $customerId = (int) $payload['customer_id'];
        $issueType = MaintenanceIssueType::from((string) $payload['issue_type']);
        $priorityRaw = isset($payload['priority']) && is_string($payload['priority']) && $payload['priority'] !== ''
            ? $payload['priority']
            : null;
        $priority = $priorityRaw !== null
            ? (MaintenancePriority::tryFrom(strtolower($priorityRaw)) ?? MaintenancePriority::Medium)
            : MaintenancePriority::Medium;

        $preferred = isset($payload['preferred_date']) && is_string($payload['preferred_date']) && $payload['preferred_date'] !== ''
            ? Carbon::parse($payload['preferred_date'])->toDateString()
            : null;

        $description = (string) $payload['description'];
        $internalNotes = isset($payload['internal_notes']) && is_string($payload['internal_notes'])
            ? $payload['internal_notes']
            : null;
        $assignedToUserId = isset($payload['assigned_to_user_id']) && is_numeric($payload['assigned_to_user_id'])
            ? (int) $payload['assigned_to_user_id']
            : null;
        $note = isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null;

        $requests = DB::transaction(function () use (
            $machineIds, $customerId, $issueType, $priority, $preferred,
            $description, $internalNotes, $assignedToUserId, $note, $actor
        ): array {
            // Lock all machines at once for consistency.
            /** @var array<int, Machine> $machines */
            $machines = Machine::query()
                ->whereIn('id', $machineIds)
                ->where('user_id', $customerId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get()
                ->keyBy('id')
                ->all();

            $results = [];
            foreach ($machineIds as $machineId) {
                if (! isset($machines[$machineId])) {
                    throw new InvalidMaintenanceTransitionException(
                        __('admin.maintenance.machine_not_owned'),
                        422,
                        'ERR_MACHINE_NOT_OWNED',
                    );
                }

                $machine = $machines[$machineId];

                /** @var MaintenanceRequest $request */
                $request = MaintenanceRequest::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $customerId,
                    'machine_id' => $machine->id,
                    'issue_type' => $issueType->value,
                    'description' => $description,
                    'internal_notes' => $internalNotes,
                    'status' => MaintenanceStatus::New->value,
                    'priority' => $priority->value,
                    'preferred_date' => $preferred,
                ]);

                MaintenanceStatusHistory::query()->create([
                    'maintenance_request_id' => $request->id,
                    'from_status' => null,
                    'to_status' => MaintenanceStatus::New->value,
                    'actor_type' => MaintenanceActorType::Admin->value,
                    'actor_id' => $actor->getAuthIdentifier(),
                    'note' => null,
                ]);

                $requestId = (int) $request->id;
                $userId = (int) $customerId;
                $mId = (int) $machine->id;
                $actorIdRaw = $actor->getAuthIdentifier();
                $actorId = is_numeric($actorIdRaw) ? (int) $actorIdRaw : $userId;
                DB::afterCommit(static function () use ($requestId, $userId, $mId, $actorId): void {
                    event(new MaintenanceRequestCreated($requestId, $userId, $mId, $actorId));
                });

                if ($assignedToUserId !== null) {
                    $request->assigned_to_user_id = $assignedToUserId;
                    $request->save();

                    $request = $this->requests->transitionTo(
                        r: $request,
                        toStatus: MaintenanceStatus::Assigned,
                        actor: $actor,
                        actorType: MaintenanceActorType::Admin,
                        payload: ['note' => $note],
                    );
                }

                $results[] = $request;
            }

            return $results;
        });

        foreach ($requests as $request) {
            $this->logActivity(
                event: 'admin.maintenance.created',
                description: 'Admin submitted maintenance request on behalf of customer',
                request: $request,
                actor: $actor,
                properties: [
                    'ip' => $ip,
                    'customer_id' => $request->user_id,
                    'machine_id' => $request->machine_id,
                    'priority' => $request->priority->value,
                ],
            );
        }

        // Reload each with full relations for the resource.
        return array_map(
            static fn (MaintenanceRequest $r): MaintenanceRequest => $r->fresh([
                'user', 'machine', 'machine.product', 'assignedTo', 'cancelledBy', 'media', 'statusHistory', 'statusHistory.actor',
            ]),
            $requests,
        );
    }

    /**
     * Assign or re-assign a technician.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assign(MaintenanceRequest $request, array $payload, User $actor, ?string $ip = null): MaintenanceRequest
    {
        if ($request->trashed()) {
            throw InvalidMaintenanceTransitionException::resourceTrashed();
        }

        // Null / omitted technician = unassign. The Form Request marks
        // the field nullable; a numeric zero is treated as null too.
        $technicianId = isset($payload['assigned_to_user_id']) && is_numeric($payload['assigned_to_user_id']) && (int) $payload['assigned_to_user_id'] > 0
            ? (int) $payload['assigned_to_user_id']
            : null;
        $note = isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null;

        $updated = DB::transaction(function () use ($request, $technicianId, $note, $actor): MaintenanceRequest {
            /** @var MaintenanceRequest $locked */
            $locked = MaintenanceRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentStatus = $locked->status;

            // Unassign: `assigned` returns to `new` (transitionTo clears
            // assigned_to_user_id); `new` is an idempotent no-op.
            if ($technicianId === null) {
                if ($currentStatus === MaintenanceStatus::Assigned) {
                    return $this->requests->transitionTo(
                        r: $locked,
                        toStatus: MaintenanceStatus::New,
                        actor: $actor,
                        actorType: MaintenanceActorType::Admin,
                        payload: ['note' => $note ?? 'Unassigned'],
                    );
                }

                if ($currentStatus === MaintenanceStatus::New) {
                    $locked->assigned_to_user_id = null;
                    $locked->save();

                    return $locked;
                }

                throw InvalidMaintenanceTransitionException::cannotAssignInState($currentStatus);
            }

            if ($currentStatus === MaintenanceStatus::New) {
                // First assignment: transition new -> assigned.
                $locked->assigned_to_user_id = $technicianId;
                $locked->save();

                return $this->requests->transitionTo(
                    r: $locked,
                    toStatus: MaintenanceStatus::Assigned,
                    actor: $actor,
                    actorType: MaintenanceActorType::Admin,
                    payload: ['note' => $note],
                );
            }

            if ($currentStatus === MaintenanceStatus::Assigned) {
                // Re-assignment: swap assignee, write a notes-only
                // history row, fire the assigned event again.
                $locked->assigned_to_user_id = $technicianId;
                $locked->save();

                MaintenanceStatusHistory::query()->create([
                    'maintenance_request_id' => $locked->id,
                    'from_status' => MaintenanceStatus::Assigned->value,
                    'to_status' => MaintenanceStatus::Assigned->value,
                    'actor_type' => MaintenanceActorType::Admin->value,
                    'actor_id' => $actor->getAuthIdentifier(),
                    'note' => $note !== null ? Str::limit($note, 480, '…') : 'Re-assigned',
                ]);

                $requestId = (int) $locked->id;
                $userId = (int) $locked->user_id;
                $actorIdRaw = $actor->getAuthIdentifier();
                $actorId = is_numeric($actorIdRaw) ? (int) $actorIdRaw : null;
                $eventNote = $note;
                DB::afterCommit(static function () use ($requestId, $userId, $actorId, $eventNote): void {
                    event(new MaintenanceRequestAssigned($requestId, $userId, $actorId, $eventNote));
                });

                return $locked;
            }

            throw InvalidMaintenanceTransitionException::cannotAssignInState($currentStatus);
        });

        $this->logActivity(
            event: 'admin.maintenance.assigned',
            description: 'Admin assigned maintenance request',
            request: $updated,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'assigned_to_user_id' => $technicianId,
                'note' => $note,
            ],
        );

        return $this->find((int) $updated->id);
    }

    /**
     * Generic status transition. Routes internally to cancel() for
     * `cancelled` and transitionTo() for `in_progress`. Rejects
     * `assigned` and `completed` with dedicated 409 codes.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transition(MaintenanceRequest $request, array $payload, User $actor, ?string $ip = null): MaintenanceRequest
    {
        if ($request->trashed()) {
            throw InvalidMaintenanceTransitionException::resourceTrashed();
        }

        // Numeric codes (1..5) resolve via tryFromCode — the Form
        // Request already normalises them, this is defence-in-depth
        // for callers that bypass it.
        $statusRaw = $payload['status'] ?? '';
        $target = is_int($statusRaw) || (is_string($statusRaw) && ctype_digit($statusRaw))
            ? MaintenanceStatus::tryFromCode((int) $statusRaw)
            : MaintenanceStatus::tryFrom((string) $statusRaw);
        if ($target === null) {
            throw InvalidMaintenanceTransitionException::from($request->status, $request->status);
        }

        // Re-route the two endpoint-specific statuses.
        if ($target === MaintenanceStatus::Assigned) {
            throw InvalidMaintenanceTransitionException::useAssignEndpoint();
        }
        if ($target === MaintenanceStatus::Completed) {
            throw InvalidMaintenanceTransitionException::useCloseEndpoint();
        }

        $note = isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null;
        $reason = isset($payload['reason']) && is_string($payload['reason']) ? $payload['reason'] : null;

        if ($target === MaintenanceStatus::Cancelled) {
            // Delegate to Phase 10 cancel() which handles
            // cancellation_reason, cancelled_by_user_id, closed_at.
            $cancelled = $this->requests->cancel($request, $actor, (string) ($reason ?? $note ?? ''));

            $this->logActivity(
                event: 'admin.maintenance.cancelled',
                description: 'Admin cancelled maintenance request',
                request: $cancelled,
                actor: $actor,
                properties: [
                    'ip' => $ip,
                    'reason' => $reason,
                ],
            );

            return $this->find((int) $cancelled->id);
        }

        // Only `in_progress` and `new` remain here. `new` is filtered
        // at the form-request layer for write attempts, but defend
        // against bypass paths by routing through canTransitionTo().
        $updated = $this->requests->transitionTo(
            r: $request,
            toStatus: $target,
            actor: $actor,
            actorType: MaintenanceActorType::Admin,
            payload: ['note' => $note],
        );

        $this->logActivity(
            event: 'admin.maintenance.status_updated',
            description: 'Admin transitioned maintenance request status',
            request: $updated,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'to_status' => $target->value,
                'note' => $note,
            ],
        );

        return $this->find((int) $updated->id);
    }

    /**
     * Close a request: write resolution_notes, transition to
     * completed, insert a machine_maintenance_logs row, recompute the
     * machine's next_maintenance_due_at.
     *
     * @param  array<string, mixed>  $payload
     */
    public function close(MaintenanceRequest $request, array $payload, User $actor, ?string $ip = null): MaintenanceRequest
    {
        if ($request->trashed()) {
            throw InvalidMaintenanceTransitionException::resourceTrashed();
        }

        // resolution_notes is optional — absent/blank stores NULL on
        // both the request and the maintenance log row.
        $resolutionNotes = isset($payload['resolution_notes']) && is_string($payload['resolution_notes']) && trim($payload['resolution_notes']) !== ''
            ? $payload['resolution_notes']
            : null;
        $completedAt = isset($payload['completed_at']) && is_string($payload['completed_at']) && $payload['completed_at'] !== ''
            ? Carbon::parse($payload['completed_at'])
            : Carbon::now();

        $closed = DB::transaction(function () use ($request, $resolutionNotes, $completedAt, $actor): MaintenanceRequest {
            /** @var MaintenanceRequest $locked */
            $locked = MaintenanceRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== MaintenanceStatus::InProgress) {
                throw InvalidMaintenanceTransitionException::onlyInProgressCanClose();
            }

            // Phase 10's transitionTo() handles status + closed_at +
            // resolution_notes when we supply it in the payload.
            $transitioned = $this->requests->transitionTo(
                r: $locked,
                toStatus: MaintenanceStatus::Completed,
                actor: $actor,
                actorType: MaintenanceActorType::Admin,
                payload: [
                    'note' => null,
                    'resolution_notes' => $resolutionNotes,
                ],
            );

            // Insert the maintenance log row.
            $actorIdRaw = $actor->getAuthIdentifier();
            $actorId = is_numeric($actorIdRaw) ? (int) $actorIdRaw : null;

            MachineMaintenanceLog::query()->create([
                'machine_id' => $transitioned->machine_id,
                'status' => MaintenanceLogStatus::Completed->value,
                'scheduled_at' => $completedAt->toDateString(),
                'completed_at' => $completedAt->toDateString(),
                'technician_user_id' => $transitioned->assigned_to_user_id,
                'notes' => $resolutionNotes,
                'created_by_user_id' => $actorId,
                'source' => MaintenanceLogSource::Request->value,
                'maintenance_request_id' => $transitioned->id,
            ]);

            // Refresh the machine's next_maintenance_due_at via the
            // Phase 10 scheduler (baseline now includes this new log).
            $machine = Machine::query()
                ->whereKey($transitioned->machine_id)
                ->lockForUpdate()
                ->first();
            if ($machine !== null) {
                $next = $this->scheduler->computeNextDue($machine);
                $machine->update(['next_maintenance_due_at' => $next?->toDateString()]);
            }

            return $transitioned;
        });

        $this->logActivity(
            event: 'admin.maintenance.closed',
            description: 'Admin closed maintenance request',
            request: $closed,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'completed_at' => $completedAt->toDateString(),
            ],
        );

        return $this->find((int) $closed->id);
    }

    /**
     * @param  Builder<MaintenanceRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        /** @var list<string>|null $statuses */
        $statuses = isset($filters['status']) && is_array($filters['status'])
            ? array_values(array_filter(
                $filters['status'],
                static fn ($v): bool => is_string($v) && $v !== '',
            ))
            : null;
        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if (isset($filters['priority']) && is_string($filters['priority']) && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }
        if (isset($filters['issue_type']) && is_string($filters['issue_type']) && $filters['issue_type'] !== '') {
            $query->where('issue_type', $filters['issue_type']);
        }

        if (array_key_exists('assigned_to_user_id', $filters) && $filters['assigned_to_user_id'] !== null && $filters['assigned_to_user_id'] !== '') {
            $assignee = (int) $filters['assigned_to_user_id'];
            if ($assignee === 0) {
                $query->whereNull('assigned_to_user_id');
            } else {
                $query->where('assigned_to_user_id', $assignee);
            }
        }

        if (isset($filters['customer_id']) && is_numeric($filters['customer_id'])) {
            $query->where('user_id', (int) $filters['customer_id']);
        }
        if (isset($filters['machine_id']) && is_numeric($filters['machine_id'])) {
            $query->where('machine_id', (int) $filters['machine_id']);
        }
        if (isset($filters['machine_ids']) && is_array($filters['machine_ids'])) {
            $ids = array_values(array_filter(
                $filters['machine_ids'],
                static fn ($v): bool => is_numeric($v),
            ));
            if ($ids !== []) {
                $query->whereIn('machine_id', array_map(static fn ($v): int => (int) $v, $ids));
            }
        }

        if (isset($filters['country_code']) && is_string($filters['country_code']) && $filters['country_code'] !== '') {
            $code = strtolower($filters['country_code']);
            $query->whereHas('machine', static function (Builder $m) use ($code): void {
                $m->where('country_code', $code);
            });
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $like = '%'.trim($filters['search']).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('description', 'LIKE', $like)
                    ->orWhere('public_id', 'LIKE', $like)
                    ->orWhereHas('user', static function (Builder $u) use ($like): void {
                        $u->where('name', 'LIKE', $like)
                            ->orWhere('email', 'LIKE', $like);
                    });
            });
        }

        if (isset($filters['date_from']) && is_string($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to']) && is_string($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * @param  Builder<MaintenanceRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'created_at';
        $sort = self::SORT_COLUMNS[$sortInput] ?? 'created_at';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'desc';
        $order = $order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sort, $order)->orderBy('id', 'desc');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        string $event,
        string $description,
        MaintenanceRequest $request,
        User $actor,
        array $properties,
    ): void {
        activity('admin.maintenance')
            ->event($event)
            ->causedBy($actor)
            ->performedOn($request)
            ->withProperties($properties)
            ->log($description);
    }
}
