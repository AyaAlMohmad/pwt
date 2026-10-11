<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Enums\MaintenanceActorType;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Events\BaseDomainEvent;
use App\Events\Maintenance\MaintenanceRequestAssigned;
use App\Events\Maintenance\MaintenanceRequestCancelled;
use App\Events\Maintenance\MaintenanceRequestCompleted;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Events\Maintenance\MaintenanceRequestInProgress;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceStatusHistory;
use App\Models\User;
// Phase 18 (admin completion path) will need MaintenanceScheduler again.
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the customer-facing maintenance request surface.
 *
 *   - `submit()`        — customer POST. Creates the request, attaches
 *                         media pivot rows, writes the initial history
 *                         entry, all inside a single `DB::transaction`.
 *   - `listForUser()`   — paginated customer list with N+1-safe eager
 *                         loads.
 *   - `findForUser()`   — single eager-loaded request (policy is asserted
 *                         by the caller controller).
 *   - `cancel()`        — customer or admin cancel; the customer path is
 *                         restricted to `status = new` by
 *                         `transitionTo()`. Phase 18 admin endpoints can
 *                         cancel from broader states.
 *   - `transitionTo()`  — generic state-machine entry-point. Phase 18
 *                         routes hit this directly.
 *
 * See docs/architecture/machines-maintenance.md § 11.2.
 */
final class MaintenanceRequestService
{
    // Reserved for Phase 18: the admin `completed` transition will call
    // MaintenanceScheduler::computeNextDue() after writing the log row.
    // Constructor injection deferred until then to keep the customer
    // surface lean (PHPStan-clean).

    /**
     * Customer submits a new maintenance request.
     *
     * @param  array{
     *     machine_id:int,
     *     issue_type:string,
     *     description:string,
     *     preferred_date?:?string,
     *     priority?:?string,
     *     media_file_ids?:list<int>,
     * }  $payload
     */
    public function submit(User $user, array $payload): MaintenanceRequest
    {
        return DB::transaction(function () use ($user, $payload): MaintenanceRequest {
            // Defence-in-depth: re-load the machine WITH the customer scope
            // even though the OwnedMachine rule already passed in the Form
            // Request. The lockForUpdate protects against a race where the
            // machine could be re-assigned between validation and write.
            $machineId = (int) $payload['machine_id'];
            $machine = Machine::query()
                ->where('id', $machineId)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($machine === null) {
                throw new ModelNotFoundException('Machine not found or not owned.');
            }

            $priority = $this->capPriority($payload['priority'] ?? null);
            $issueType = MaintenanceIssueType::from((string) $payload['issue_type']);

            $preferred = $payload['preferred_date'] ?? null;
            $preferredDate = is_string($preferred) && $preferred !== ''
                ? Carbon::parse($preferred)->toDateString()
                : null;

            $request = MaintenanceRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'machine_id' => $machine->id,
                'issue_type' => $issueType->value,
                'description' => (string) $payload['description'],
                'status' => MaintenanceStatus::New->value,
                'priority' => $priority->value,
                'preferred_date' => $preferredDate,
                'preferred_time' => isset($payload['preferred_time']) && $payload['preferred_time'] !== ''
                    ? $payload['preferred_time']
                    : null,
            ]);

            // Attach media via the pivot in one shot. The custom OwnedFile
            // rule has already verified ownership + type + one-shot
            // exclusivity; we trust the validated payload here.
            $mediaIds = isset($payload['media_file_ids'])
                ? array_map(static fn ($id): int => (int) $id, (array) $payload['media_file_ids'])
                : [];
            if ($mediaIds !== []) {
                $request->media()->syncWithoutDetaching($mediaIds);
            }

            // Initial history row — actor is the submitting customer.
            MaintenanceStatusHistory::query()->create([
                'maintenance_request_id' => $request->id,
                'from_status' => null,
                'to_status' => MaintenanceStatus::New->value,
                'actor_type' => MaintenanceActorType::Customer->value,
                'actor_id' => $user->id,
                'note' => null,
            ]);

            // Phase 11 — fire MaintenanceRequestCreated after commit.
            $requestId = (int) $request->id;
            $userId = (int) $user->id;
            $machineId = (int) $machine->id;
            DB::afterCommit(static function () use ($requestId, $userId, $machineId): void {
                event(new MaintenanceRequestCreated($requestId, $userId, $machineId, $userId));
            });

            return $this->eagerLoad($request->refresh());
        });
    }

    /**
     * Customer submits maintenance requests for multiple machines in one batch.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, MaintenanceRequest>
     */
    public function submitBatch(User $user, array $payload): array
    {
        $machineIds = isset($payload['machine_ids']) && is_array($payload['machine_ids'])
            ? array_map(static fn ($id): int => (int) $id, $payload['machine_ids'])
            : [];

        if ($machineIds === []) {
            throw new ModelNotFoundException('No machines were selected for batch creation.');
        }

        $priority = $this->capPriority($payload['priority'] ?? null);
        $issueType = MaintenanceIssueType::from((string) $payload['issue_type']);

        $preferred = $payload['preferred_date'] ?? null;
        $preferredDate = is_string($preferred) && $preferred !== ''
            ? Carbon::parse($preferred)->toDateString()
            : null;

        $preferredTime = isset($payload['preferred_time']) && $payload['preferred_time'] !== ''
            ? $payload['preferred_time']
            : null;
        $description = (string) $payload['description'];

        $mediaIds = isset($payload['media_file_ids'])
            ? array_map(static fn ($id): int => (int) $id, (array) $payload['media_file_ids'])
            : [];

        $requests = DB::transaction(function () use (
            $user, $machineIds, $priority, $issueType, $preferredDate,
            $preferredTime, $description, $mediaIds
        ): array {
            // Lock all machines at once.
            /** @var array<int, Machine> $machines */
            $machines = Machine::query()
                ->whereIn('id', $machineIds)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id')
                ->all();

            $results = [];
            foreach ($machineIds as $machineId) {
                if (! isset($machines[$machineId])) {
                    throw new ModelNotFoundException('Machine not found or not owned.');
                }

                $machine = $machines[$machineId];

                $request = MaintenanceRequest::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'machine_id' => $machine->id,
                    'issue_type' => $issueType->value,
                    'description' => $description,
                    'status' => MaintenanceStatus::New->value,
                    'priority' => $priority->value,
                    'preferred_date' => $preferredDate,
                    'preferred_time' => $preferredTime,
                ]);

                if ($mediaIds !== []) {
                    $request->media()->syncWithoutDetaching($mediaIds);
                }

                MaintenanceStatusHistory::query()->create([
                    'maintenance_request_id' => $request->id,
                    'from_status' => null,
                    'to_status' => MaintenanceStatus::New->value,
                    'actor_type' => MaintenanceActorType::Customer->value,
                    'actor_id' => $user->id,
                    'note' => null,
                ]);

                $requestId = (int) $request->id;
                $userId = (int) $user->id;
                $mId = (int) $machine->id;
                DB::afterCommit(static function () use ($requestId, $userId, $mId): void {
                    event(new MaintenanceRequestCreated($requestId, $userId, $mId, $userId));
                });

                $results[] = $request;
            }

            return $results;
        });

        // Reload each with full relations.
        return array_map(
            static fn (MaintenanceRequest $r): MaintenanceRequest => $r->fresh([
                'machine.product', 'machine.address.country', 'assignedTo', 'media', 'statusHistory.actor',
            ]),
            $requests,
        );
    }

    /**
     * Paginated customer-scoped list with N+1-safe eager-loads.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, MaintenanceRequest>
     */
    public function listForUser(
        User $user,
        array $filters,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $query = MaintenanceRequest::query()
            ->with([
                'machine.product',
            ])
            ->forUser((int) $user->id);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->withStatus($filters['status']);
        }

        if (isset($filters['date_from']) && is_string($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to']) && is_string($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : 'created_at';
        $order = isset($filters['order']) && is_string($filters['order']) ? $filters['order'] : 'desc';
        $query->orderBy($sort, $order)->orderBy('id', 'desc');

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Single eager-loaded request. Caller asserts the Policy.
     */
    public function findForUser(MaintenanceRequest $r, User $u): MaintenanceRequest
    {
        if ($r->user_id !== $u->id) {
            // Defensive — the Policy is the primary gate. Keeps the
            // service safe for downstream reuse.
            abort(403);
        }

        return $this->eagerLoad($r);
    }

    /**
     * Customer reschedules an existing maintenance request.
     * Allowed when the request is in a non-terminal state.
     *
     * @param  array{preferred_date:string,preferred_time?:?string}  $payload
     */
    public function reschedule(MaintenanceRequest $r, User $actor, array $payload): MaintenanceRequest
    {
        return DB::transaction(function () use ($r, $actor, $payload): MaintenanceRequest {
            /** @var MaintenanceRequest $locked */
            $locked = MaintenanceRequest::query()
                ->whereKey($r->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw InvalidMaintenanceTransitionException::cannotReschedule($locked->status);
            }

            $oldDate = $locked->preferred_date?->toDateString() ?? 'none';
            $oldTime = $locked->preferred_time ?? 'none';

            $locked->preferred_date = $payload['preferred_date'];
            $locked->preferred_time = $payload['preferred_time'] ?? null;
            $locked->save();

            MaintenanceStatusHistory::query()->create([
                'maintenance_request_id' => $locked->id,
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'actor_type' => MaintenanceActorType::Customer->value,
                'actor_id' => $actor->id,
                'note' => "Rescheduled from {$oldDate} ({$oldTime}) to {$payload['preferred_date']} (".($payload['preferred_time'] ?? 'anytime').').',
            ]);

            return $this->eagerLoad($locked->refresh());
        });
    }

    /**
     * Customer or admin cancel.
     *
     *  - Customer path: actor must own the request AND status === `new`.
     *  - Admin path (Phase 18): permission-gated; cancel from any
     *    non-terminal state.
     */
    public function cancel(MaintenanceRequest $r, User $actor, string $reason): MaintenanceRequest
    {
        return DB::transaction(function () use ($r, $actor, $reason): MaintenanceRequest {
            /** @var MaintenanceRequest $locked */
            $locked = MaintenanceRequest::query()
                ->whereKey($r->id)
                ->lockForUpdate()
                ->firstOrFail();

            $actorType = $locked->user_id === $actor->id
                ? MaintenanceActorType::Customer
                : MaintenanceActorType::Admin;

            // Customer cancel is allowed only from `new`. Admin cancel is
            // allowed from any non-terminal state.
            if (
                $actorType === MaintenanceActorType::Customer
                && $locked->status !== MaintenanceStatus::New
            ) {
                throw InvalidMaintenanceTransitionException::cannotCancel($locked->status);
            }

            $locked->cancellation_reason = $reason;
            $locked->cancelled_by_user_id = $actor->id;
            $locked->save();

            return $this->transitionTo(
                r: $locked,
                toStatus: MaintenanceStatus::Cancelled,
                actor: $actor,
                actorType: $actorType,
                payload: ['note' => Str::limit($reason, 480, '…')],
            );
        });
    }

    /**
     * Generic state-machine entry-point. Phase 18 admin endpoints call
     * this with `assigned`, `in_progress`, `completed` targets.
     *
     * @param  array{note?:?string, resolution_notes?:?string}  $payload
     */
    public function transitionTo(
        MaintenanceRequest $r,
        MaintenanceStatus $toStatus,
        User $actor,
        MaintenanceActorType $actorType,
        array $payload = [],
    ): MaintenanceRequest {
        return DB::transaction(function () use ($r, $toStatus, $actor, $actorType, $payload): MaintenanceRequest {
            /** @var MaintenanceRequest $locked */
            $locked = MaintenanceRequest::query()
                ->whereKey($r->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;
            if (! $from->canTransitionTo($toStatus)) {
                throw InvalidMaintenanceTransitionException::from($from, $toStatus);
            }

            // Side-effects: closed_at on terminal transitions, optional
            // resolution_notes on `completed`. A return to `new`
            // (unassign) clears the technician slot.
            if ($toStatus->isTerminal() && $locked->closed_at === null) {
                $locked->closed_at = now();
            }
            if ($toStatus === MaintenanceStatus::New) {
                $locked->assigned_to_user_id = null;
            }
            if (
                $toStatus === MaintenanceStatus::Completed
                && isset($payload['resolution_notes'])
            ) {
                $locked->resolution_notes = (string) $payload['resolution_notes'];
            }

            $locked->status = $toStatus;
            $locked->save();

            MaintenanceStatusHistory::query()->create([
                'maintenance_request_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $toStatus->value,
                'actor_type' => $actorType->value,
                'actor_id' => $actor->getAuthIdentifier(),
                'note' => isset($payload['note']) ? (string) $payload['note'] : null,
            ]);

            // Phase 11 — dispatch the matching domain event after commit.
            $event = $this->buildEventFor($toStatus, $locked, $actor, $payload['note'] ?? null);
            if ($event !== null) {
                DB::afterCommit(static function () use ($event): void {
                    event($event);
                });
            }

            return $this->eagerLoad($locked->refresh());
        });
    }

    /**
     * Build the Phase 11 notification event for a given maintenance
     * status target. Returns null for targets without customer-facing
     * notification copy.
     */
    private function buildEventFor(
        MaintenanceStatus $target,
        MaintenanceRequest $request,
        User $actor,
        ?string $note,
    ): ?BaseDomainEvent {
        $actorId = $actor->getAuthIdentifier();
        $actorIdInt = is_numeric($actorId) ? (int) $actorId : null;
        $requestId = (int) $request->id;
        $userId = (int) $request->user_id;

        return match ($target) {
            MaintenanceStatus::Assigned => new MaintenanceRequestAssigned($requestId, $userId, $actorIdInt, $note),
            MaintenanceStatus::InProgress => new MaintenanceRequestInProgress($requestId, $userId, $actorIdInt, $note),
            MaintenanceStatus::Completed => new MaintenanceRequestCompleted($requestId, $userId, $actorIdInt, $note),
            MaintenanceStatus::Cancelled => new MaintenanceRequestCancelled($requestId, $userId, $actorIdInt, $note),
            default => null,
        };
    }

    /**
     * Centralised eager-load so list / detail / cancel share a profile.
     */
    public function eagerLoad(MaintenanceRequest $r): MaintenanceRequest
    {
        return $r->load([
            'machine.product',
            'machine.address.country',
            'assignedTo',
            'media',
            'statusHistory.actor',
        ]);
    }

    /**
     * Clamp customer-supplied priority to the medium cap. Unknown values
     * fall through to medium too — the Form Request also pre-clamps but
     * the service is the last line of defence.
     */
    private function capPriority(?string $raw): MaintenancePriority
    {
        if ($raw === null || $raw === '') {
            return MaintenancePriority::Medium;
        }

        $clean = strtolower(trim($raw));
        $candidate = MaintenancePriority::tryFrom($clean);
        if ($candidate === null) {
            return MaintenancePriority::Medium;
        }

        return match ($candidate) {
            MaintenancePriority::Low => MaintenancePriority::Low,
            MaintenancePriority::Medium,
            MaintenancePriority::High,
            MaintenancePriority::Urgent => MaintenancePriority::Medium,
        };
    }
}
