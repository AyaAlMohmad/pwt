<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\SupportTicketPriority;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side support ticket orchestrator.
 *
 * Cross-cutting:
 *  - Every transition / write still flows through Phase 12's
 *    {@see SupportTicketService} so the state machine has a single
 *    source of truth. This class is responsible for:
 *      - admin-specific filtering / pagination,
 *      - eager-loading the admin detail shape (full thread including
 *        internal notes, full actor identities),
 *      - orchestrating multi-field PATCH atomically,
 *      - writing the explicit `admin.support` Spatie activity row.
 *  - All write methods wrap in `DB::transaction` so a multi-field
 *    PATCH (priority + assignment + status) either fully succeeds or
 *    fully rolls back.
 *
 * See docs/architecture/admin-support.md § 7.5.
 */
final class AdminSupportTicketService
{
    /**
     * Whitelisted sort columns -> actual DB columns. Mirrors Phase 18
     * `AdminMaintenanceService::SORT_COLUMNS`.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'last_message_at' => 'last_message_at',
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
        'opened_at' => 'opened_at',
    ];

    public function __construct(
        private readonly SupportTicketService $tickets,
    ) {}

    /**
     * Paginated list across ALL support tickets with admin filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<SupportTicket> $query */
        $query = $includeTrashed
            ? SupportTicket::query()->withTrashed()
            : SupportTicket::query();

        $query
            ->addSelect(['support_tickets.*'])
            ->addSelect([
                'message_count' => SupportTicketMessage::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_ticket_id', 'support_tickets.id'),
                'internal_note_count' => SupportTicketMessage::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_ticket_id', 'support_tickets.id')
                    ->where('is_internal_note', true),
            ])
            ->with([
                'user:id,name,email,phone,phone_country_code',
                'assignedTo:id,name',
                'assignedTo.roles:id,name',
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Full-detail load with `withTrashed()` and the admin eager-load
     * graph (full thread INCLUDING internal notes, full actor
     * identities on the status history).
     */
    public function find(int $id): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()
            ->withTrashed()
            ->with([
                'user:id,name,email,phone,phone_country_code,is_active,deleted_at',
                'assignedTo:id,name',
                'cancelledBy:id,name',
                'relatedOrder',
                'relatedMachine.product:id,code,name',
                'messages' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                'messages.sender:id,name',
                'messages.attachments.file',
                'statusHistory' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
                'statusHistory.actor:id,name',
            ])
            ->findOrFail($id);

        // Hydrate roles for every actor user we surface in the
        // response so the resource can include `role` without an N+1
        // storm. Same pattern as Phase 18 admin-maintenance § find().
        $userIds = collect([
            $ticket->assignedTo?->id,
            $ticket->cancelledBy?->id,
        ])
            ->merge(
                $ticket->messages
                    ->map(static fn (SupportTicketMessage $m) => $m->sender?->id),
            )
            ->merge(
                $ticket->statusHistory
                    ->map(static fn (SupportTicketStatusHistory $h) => $h->actor?->id),
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

            if ($ticket->assignedTo !== null && isset($users[$ticket->assignedTo->id])) {
                $ticket->setRelation('assignedTo', $users[$ticket->assignedTo->id]);
            }
            if ($ticket->cancelledBy !== null && isset($users[$ticket->cancelledBy->id])) {
                $ticket->setRelation('cancelledBy', $users[$ticket->cancelledBy->id]);
            }
            foreach ($ticket->messages as $message) {
                $sender = $message->sender;
                if ($sender !== null && isset($users[$sender->id])) {
                    $message->setRelation('sender', $users[$sender->id]);
                }
            }
            foreach ($ticket->statusHistory as $row) {
                $actor = $row->actor;
                if ($actor !== null && isset($users[$actor->id])) {
                    $row->setRelation('actor', $users[$actor->id]);
                }
            }
        }

        return $ticket;
    }

    /**
     * Apply a multi-field PATCH atomically — priority first, then
     * standalone assignment (skipped when status=assigned is supplied),
     * then status transition. Each is a no-op when the field is absent.
     *
     * Wraps in DB::transaction. Writes a single `admin.support`
     * activity row at the end with the full diff.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(SupportTicket $ticket, array $payload, User $admin, ?string $ip = null): SupportTicket
    {
        $updated = DB::transaction(function () use ($ticket, $payload, $admin): SupportTicket {
            $current = $ticket;

            // 1. Priority change — no history row, only LogsActivity.
            if (array_key_exists('priority', $payload) && is_string($payload['priority']) && $payload['priority'] !== '') {
                $current = $this->tickets->updatePriority(
                    $current,
                    SupportTicketPriority::from($payload['priority']),
                    $admin,
                );
            }

            // 2. Assignment — column-only or full transition.
            // When status=assigned AND assigned_to_user_id supplied, the
            // assignment is consumed by transitionTo() below; skip the
            // standalone assign() call.
            $isStatusAssign = ($payload['status'] ?? null) === 'assigned';
            if (! $isStatusAssign && array_key_exists('assigned_to_user_id', $payload)) {
                $assigneeRaw = $payload['assigned_to_user_id'];
                $assigneeId = $assigneeRaw === null ? null : (int) $assigneeRaw;
                $current = $this->tickets->assign(
                    $current,
                    $assigneeId,
                    $admin,
                    isset($payload['note']) && is_string($payload['note']) ? $payload['note'] : null,
                );
            }

            // 3. Status transition (consumes assigned_to_user_id when
            //    target=assigned, resolution_summary when target=resolved,
            //    cancellation_reason when target=cancelled).
            if (array_key_exists('status', $payload) && is_string($payload['status']) && $payload['status'] !== '') {
                $current = $this->tickets->transitionTo($current, [
                    'status' => $payload['status'],
                    'assigned_to_user_id' => $payload['assigned_to_user_id'] ?? null,
                    'resolution_summary' => $payload['resolution_summary'] ?? null,
                    'cancellation_reason' => $payload['cancellation_reason'] ?? null,
                    'note' => $payload['note'] ?? null,
                ], $admin);
            }

            return $current;
        });

        $this->logActivity(
            event: 'admin.support.updated',
            description: 'Admin updated support ticket',
            ticket: $updated,
            actor: $admin,
            properties: [
                'ip' => $ip,
                'payload' => Arr::except($payload, ['note']),
            ],
        );

        return $this->find((int) $updated->id);
    }

    /**
     * Post a reply on behalf of the admin. Wraps
     * {@see SupportTicketService::reply()} with the explicit
     * `admin.support` activity log row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reply(SupportTicket $ticket, array $payload, User $admin, ?string $ip = null): SupportTicket
    {
        $isInternal = (bool) ($payload['is_internal'] ?? false);
        $rawFileIds = $payload['attachment_file_ids'] ?? null;
        $fileIds = is_array($rawFileIds)
            ? array_values(array_map(static fn ($id): int => (int) $id, $rawFileIds))
            : [];

        $message = $this->tickets->reply($ticket, [
            'body' => (string) $payload['body'],
            'is_internal' => $isInternal,
            'attachment_file_ids' => $fileIds,
        ], $admin);

        $this->logActivity(
            event: 'admin.support.replied',
            description: $isInternal
                ? 'Admin added internal note to support ticket'
                : 'Admin replied to support ticket',
            ticket: $ticket,
            actor: $admin,
            properties: [
                'ip' => $ip,
                'message_id' => $message->id,
                'is_internal' => $isInternal,
                'attachment_count' => count($fileIds),
            ],
        );

        return $this->find((int) $ticket->id);
    }

    /**
     * @param  Builder<SupportTicket>  $query
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

        if (isset($filters['category']) && is_string($filters['category']) && $filters['category'] !== '') {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['priority']) && is_string($filters['priority']) && $filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
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

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $like = '%'.trim($filters['search']).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('subject', 'LIKE', $like)
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
     * @param  Builder<SupportTicket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'last_message_at';
        $sort = self::SORT_COLUMNS[$sortInput] ?? 'last_message_at';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'desc';
        $order = $order === 'asc' ? 'asc' : 'desc';

        // For `last_message_at`, fall back to created_at when null so
        // legacy tickets with no message activity still rank reasonably.
        if ($sort === 'last_message_at') {
            $query->orderByRaw('COALESCE(support_tickets.last_message_at, support_tickets.created_at) '.$order);
            $query->orderBy('id', 'desc');

            return;
        }

        $query->orderBy($sort, $order)->orderBy('id', 'desc');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        string $event,
        string $description,
        SupportTicket $ticket,
        User $actor,
        array $properties,
    ): void {
        activity('admin.support')
            ->event($event)
            ->causedBy($actor)
            ->performedOn($ticket)
            ->withProperties($properties)
            ->log($description);
    }
}
