<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketMessageSenderType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Events\Support\SupportTicketAssigned;
use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketClosed;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketInProgress;
use App\Events\Support\SupportTicketReplied;
use App\Events\Support\SupportTicketResolved;
use App\Exceptions\Support\InvalidSupportTransitionException;
use App\Exceptions\Support\TicketTerminalException;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketStatusHistory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the customer-facing support ticket surface.
 *
 *   - `submit()`        — customer POST. Creates the ticket, initial
 *                         message, attachment pivot rows, first status
 *                         history entry, all in one DB::transaction.
 *                         Fires `SupportTicketCreated` via DB::afterCommit.
 *   - `listForUser()`   — paginated customer list with subquery-driven
 *                         `last_message_at` sort. N+1-safe eager loads.
 *   - `findForUser()`   — single eager-loaded ticket (policy is asserted
 *                         by the caller controller). Internal notes are
 *                         filtered at the query layer.
 *   - `cancel()`        — customer cancel from `open`. Wraps in
 *                         DB::transaction + SELECT FOR UPDATE. Fires
 *                         `SupportTicketCancelled` via DB::afterCommit.
 *
 * See docs/architecture/support.md § 7.
 */
final class SupportTicketService
{
    /**
     * Customer submits a new support ticket.
     *
     * @param  array{
     *     subject: string,
     *     category: string,
     *     message: string,
     *     related_order_id?: int|null,
     *     related_machine_id?: int|null,
     *     attachment_file_ids?: list<int>|null,
     * }  $payload
     */
    public function submit(User $user, array $payload): SupportTicket
    {
        $ticket = DB::transaction(function () use ($user, $payload): SupportTicket {
            $category = SupportTicketCategory::from((string) $payload['category']);

            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => (int) $user->id,
                'related_order_id' => isset($payload['related_order_id'])
                    ? (int) $payload['related_order_id']
                    : null,
                'related_machine_id' => isset($payload['related_machine_id'])
                    ? (int) $payload['related_machine_id']
                    : null,
                'subject' => (string) $payload['subject'],
                'category' => $category->value,
                'priority' => SupportTicketPriority::Medium->value,
                'status' => SupportTicketStatus::Open->value,
                'opened_at' => now(),
            ]);

            // First message — always the customer body.
            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()->create([
                'support_ticket_id' => $ticket->id,
                'sender_type' => SupportTicketMessageSenderType::Customer->value,
                'sender_user_id' => (int) $user->id,
                'body' => (string) $payload['message'],
                'is_internal_note' => false,
            ]);

            // Phase 19 denormalised column — kept in sync with the
            // newly-inserted message so the admin list sort works on
            // freshly-created tickets without a backfill.
            $ticket->forceFill([
                'last_message_at' => $message->created_at,
            ])->save();

            // Attachments — one pivot row per file id. The custom
            // OwnedFile rule has already verified ownership + type +
            // one-shot exclusivity; we trust the validated payload here.
            $rawFileIds = $payload['attachment_file_ids'] ?? null;
            $fileIds = is_array($rawFileIds)
                ? array_map(static fn ($id): int => (int) $id, $rawFileIds)
                : [];

            foreach ($fileIds as $fileId) {
                SupportTicketAttachment::query()->create([
                    'support_ticket_id' => $ticket->id,
                    'support_ticket_message_id' => $message->id,
                    'file_id' => $fileId,
                    'uploaded_by_user_id' => (int) $user->id,
                ]);
            }

            // First history row — actor is the submitting customer.
            SupportTicketStatusHistory::query()->create([
                'support_ticket_id' => $ticket->id,
                'from_status' => null,
                'to_status' => SupportTicketStatus::Open->value,
                'actor_type' => 'customer',
                'actor_user_id' => (int) $user->id,
                'note' => null,
            ]);

            // Phase 11 — fire SupportTicketCreated after commit.
            $ticketId = (int) $ticket->id;
            $userId = (int) $user->id;
            DB::afterCommit(static function () use ($ticketId, $userId): void {
                event(new SupportTicketCreated($ticketId, $userId, $userId));
            });

            return $ticket;
        });

        return $this->findForUser($ticket->refresh(), $user);
    }

    /**
     * Paginated customer-scoped list with N+1-safe eager-loads.
     *
     * Sort: COALESCE(last_message_at, created_at) DESC. The
     * `last_message_at` subquery filters internal notes so the customer
     * inbox never reorders based on admin-only chatter.
     *
     * @param  array{status?: string|list<string>|null, category?: string|null}  $filters
     * @return LengthAwarePaginator<int, SupportTicket>
     */
    public function listForUser(
        User $user,
        array $filters,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $status = $filters['status'] ?? null;
        $rawCategory = $filters['category'] ?? null;
        $category = is_string($rawCategory) ? $rawCategory : null;

        return SupportTicket::query()
            ->forUser((int) $user->id)
            ->withCategory($category)
            ->withStatus(is_array($status) || is_string($status) ? $status : null)
            ->addSelect(['support_tickets.*'])
            // Phase 19: COALESCE the persisted denormalised
            // `last_message_at` column with the legacy subquery so
            // freshly-migrated tickets still sort sensibly. The
            // subquery is preserved here only as a fallback when the
            // column is NULL.
            ->addSelect([
                'last_message_subquery' => SupportTicketMessage::query()
                    ->select('created_at')
                    ->whereColumn('support_ticket_id', 'support_tickets.id')
                    ->where('is_internal_note', false)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(1),
            ])
            // Real unread count — admin (non-internal) messages newer
            // than the customer's last detail view.
            ->addSelect([
                'unread_message_count' => SupportTicketMessage::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_ticket_id', 'support_tickets.id')
                    ->where('sender_type', SupportTicketMessageSenderType::Admin->value)
                    ->where('is_internal_note', false)
                    ->whereRaw(
                        'support_ticket_messages.created_at > COALESCE(support_tickets.last_customer_view_at, "1970-01-01")',
                    ),
            ])
            ->with(['assignedTo:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Eager-load the detail view. Customer-side filtering of internal
     * notes happens at the query layer here (defence-in-depth — the
     * resource layer ALSO filters in case future code paths bypass
     * this service).
     *
     * Caller asserts the Policy.
     */
    public function findForUser(SupportTicket $ticket, User $user): SupportTicket
    {
        if ($ticket->user_id !== $user->id) {
            // Defensive — the Policy is the primary gate. Keeps the
            // service safe for downstream reuse.
            abort(403);
        }

        $ticket->loadMissing([
            'messages' => fn ($q) => $q
                ->where('is_internal_note', false)
                ->orderBy('created_at')
                ->orderBy('id'),
            'messages.sender:id,name',
            'messages.attachments.file',
            'relatedOrder',
            'relatedMachine.product',
            'assignedTo:id,name',
            'statusHistory' => fn ($q) => $q
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);

        return $ticket;
    }

    /**
     * Customer cancel. Allowed only from `open`.
     *
     * Wraps the read + status-check + write in DB::transaction with
     * SELECT ... FOR UPDATE so concurrent cancel calls produce a single
     * state change.
     */
    public function cancel(SupportTicket $ticket, User $actor, ?string $reason): SupportTicket
    {
        $resolved = DB::transaction(function () use ($ticket, $actor, $reason): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isCustomerCancellable()) {
                throw InvalidSupportTransitionException::cannotCancel($locked->status);
            }

            $from = $locked->status;
            $trimmed = $reason !== null ? trim($reason) : null;
            if ($trimmed === '') {
                $trimmed = null;
            }

            $locked->forceFill([
                'status' => SupportTicketStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => (int) $actor->id,
                'cancellation_reason' => $trimmed,
            ])->save();

            SupportTicketStatusHistory::query()->create([
                'support_ticket_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => SupportTicketStatus::Cancelled->value,
                'actor_type' => 'customer',
                'actor_user_id' => (int) $actor->id,
                'note' => $trimmed !== null ? Str::limit($trimmed, 480, '…') : null,
            ]);

            $ticketId = (int) $locked->id;
            $userId = (int) $locked->user_id;
            $actorId = (int) $actor->id;
            $reasonCopy = $trimmed;
            DB::afterCommit(static function () use ($ticketId, $userId, $reasonCopy, $actorId): void {
                event(new SupportTicketCancelled($ticketId, $userId, $reasonCopy, $actorId));
            });

            return $locked;
        });

        return $this->findForUser($resolved->refresh(), $actor);
    }

    /**
     * Phase 19 — stamp `last_customer_view_at` whenever the customer
     * fetches the detail endpoint. Idempotent; no event fired. Drives
     * the customer-side `unread_message_count` accessor.
     *
     * Defensive: silently no-ops when the ticket does not belong to the
     * caller (the Policy should have already rejected, but we double-
     * check so a misconfigured route never leaks a peer's read marker).
     */
    public function markCustomerViewed(SupportTicket $ticket, User $customer): void
    {
        if ($ticket->user_id !== $customer->id) {
            return;
        }

        SupportTicket::query()
            ->whereKey($ticket->id)
            ->update(['last_customer_view_at' => now()]);
    }

    /**
     * Phase 19 — admin-driven status transition. Validates current →
     * target against {@see SupportTicketStatus::transitionsAllowed()}.
     *
     * Wraps the read + check + write in DB::transaction with
     * `lockForUpdate()`. Inserts a status-history row. Fires the
     * matching domain event via `DB::afterCommit`.
     *
     * @param  array{
     *     status: string,
     *     assigned_to_user_id?: int|null,
     *     resolution_summary?: string|null,
     *     cancellation_reason?: string|null,
     *     note?: string|null,
     * }  $payload
     *
     * @throws InvalidSupportTransitionException 409 ERR_INVALID_STATUS_TRANSITION
     * @throws TicketTerminalException 409 ERR_RESOURCE_TRASHED
     */
    public function transitionTo(SupportTicket $ticket, array $payload, User $admin): SupportTicket
    {
        if ($ticket->trashed()) {
            throw TicketTerminalException::resourceTrashed();
        }

        $target = SupportTicketStatus::from((string) $payload['status']);

        $resolved = DB::transaction(function () use ($ticket, $target, $payload, $admin): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw InvalidSupportTransitionException::from($from, $target);
            }

            $rawNote = $payload['note'] ?? null;
            $note = $rawNote !== null && trim($rawNote) !== '' ? trim($rawNote) : null;

            $update = [
                'status' => $target->value,
            ];

            // Target-specific side-effects.
            switch ($target) {
                case SupportTicketStatus::Assigned:
                    $assigneeRaw = $payload['assigned_to_user_id'] ?? null;
                    if ($assigneeRaw !== null) {
                        $update['assigned_to_user_id'] = (int) $assigneeRaw;
                    }
                    break;

                case SupportTicketStatus::Resolved:
                    $summaryRaw = $payload['resolution_summary'] ?? null;
                    $summary = $summaryRaw !== null ? trim($summaryRaw) : null;
                    $update['resolution_summary'] = $summary !== null && $summary !== '' ? $summary : null;
                    break;

                case SupportTicketStatus::Closed:
                    $update['closed_at'] = now();
                    break;

                case SupportTicketStatus::Cancelled:
                    $reasonRaw = $payload['cancellation_reason'] ?? null;
                    $reason = $reasonRaw !== null ? trim($reasonRaw) : null;
                    $update['cancelled_at'] = now();
                    $update['cancelled_by_user_id'] = (int) $admin->id;
                    $update['cancellation_reason'] = $reason !== null && $reason !== '' ? $reason : null;
                    break;

                default:
                    // in_progress — no extra columns.
                    break;
            }

            // Auto-stamp first_response_at on the first customer-visible
            // admin touch out of `open`. Internal-note replies are not
            // counted (handled in reply()); transitions ARE counted
            // because every transition out of `open` produces a customer-
            // facing notification.
            if ($locked->first_response_at === null && $target !== SupportTicketStatus::Open) {
                $update['first_response_at'] = now();
            }

            $locked->forceFill($update)->save();

            SupportTicketStatusHistory::query()->create([
                'support_ticket_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'actor_type' => 'admin',
                'actor_user_id' => (int) $admin->id,
                'note' => $note !== null ? Str::limit($note, 480, '…') : null,
            ]);

            $this->dispatchTransitionEvent($locked, $target, $admin, $update);

            return $locked;
        });

        return $resolved->refresh();
    }

    /**
     * Phase 19 — admin assign / re-assign. From `open` triggers the
     * full `transitionTo(Assigned)` path. From other non-terminal
     * states: idempotent re-assign (column-only + notes history row).
     *
     * Setting `$assignedToUserId = null` un-assigns; the status stays
     * the same (no auto-revert to `open` — see admin-support.md § 9.8).
     *
     * @throws TicketTerminalException 409 ERR_RESOURCE_TRASHED
     * @throws InvalidSupportTransitionException 409 when current status is terminal
     */
    public function assign(SupportTicket $ticket, ?int $assignedToUserId, User $admin, ?string $note): SupportTicket
    {
        if ($ticket->trashed()) {
            throw TicketTerminalException::resourceTrashed();
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        // If we're coming from `open` AND a non-null assignee is supplied,
        // route through the full transition path so the status changes
        // and the customer-visible `SupportTicketAssigned` notification
        // fires.
        if ($ticket->status === SupportTicketStatus::Open && $assignedToUserId !== null) {
            return $this->transitionTo($ticket, [
                'status' => SupportTicketStatus::Assigned->value,
                'assigned_to_user_id' => $assignedToUserId,
                'note' => $note,
            ], $admin);
        }

        $resolved = DB::transaction(function () use ($ticket, $assignedToUserId, $admin, $note): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw InvalidSupportTransitionException::from($locked->status, $locked->status);
            }

            $locked->forceFill([
                'assigned_to_user_id' => $assignedToUserId,
            ])->save();

            SupportTicketStatusHistory::query()->create([
                'support_ticket_id' => $locked->id,
                'from_status' => $locked->status->value,
                'to_status' => $locked->status->value,
                'actor_type' => 'admin',
                'actor_user_id' => (int) $admin->id,
                'note' => $note ?? ($assignedToUserId === null ? 'Un-assigned' : 'Re-assigned'),
            ]);

            // Only fire the ops-side notification on a real re-assignment.
            // Un-assignment is silent (no admin to notify).
            if ($assignedToUserId !== null) {
                $ticketId = (int) $locked->id;
                $newAssigneeId = $assignedToUserId;
                $actorId = (int) $admin->id;
                DB::afterCommit(static function () use ($ticketId, $newAssigneeId, $actorId): void {
                    event(new SupportTicketAssigned($ticketId, $newAssigneeId, $actorId));
                });
            }

            return $locked;
        });

        return $resolved->refresh();
    }

    /**
     * Phase 19 — admin priority update. No history row (priority
     * changes are captured by Spatie LogsActivity on the model). Status
     * is unchanged.
     *
     * @throws TicketTerminalException 409 ERR_RESOURCE_TRASHED
     */
    public function updatePriority(SupportTicket $ticket, SupportTicketPriority $priority, User $admin): SupportTicket
    {
        if ($ticket->trashed()) {
            throw TicketTerminalException::resourceTrashed();
        }

        unset($admin);

        $resolved = DB::transaction(function () use ($ticket, $priority): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill(['priority' => $priority->value])->save();

            return $locked;
        });

        return $resolved->refresh();
    }

    /**
     * Phase 19 — admin reply.
     *
     * Inserts a {@see SupportTicketMessage} (sender_type=admin), pivots
     * any attachments, updates `last_message_at`, and stamps
     * `first_response_at` when currently null AND the reply is NOT an
     * internal note.
     *
     * Internal notes are permitted on terminal tickets (admins may
     * annotate audited cases). Customer-visible replies on terminal
     * tickets raise {@see TicketTerminalException}.
     *
     * @param  array{
     *     body: string,
     *     is_internal?: bool,
     *     attachment_file_ids?: list<int>,
     * }  $payload
     *
     * @throws TicketTerminalException 409
     */
    public function reply(SupportTicket $ticket, array $payload, User $admin): SupportTicketMessage
    {
        if ($ticket->trashed()) {
            throw TicketTerminalException::resourceTrashed();
        }

        $isInternal = (bool) ($payload['is_internal'] ?? false);

        if (! $isInternal && $ticket->status->isTerminal()) {
            throw TicketTerminalException::cannotReplyToTerminal();
        }

        $body = trim((string) $payload['body']);
        $rawFileIds = $payload['attachment_file_ids'] ?? null;
        $fileIds = is_array($rawFileIds)
            ? array_map(static fn ($id): int => (int) $id, $rawFileIds)
            : [];

        $message = DB::transaction(function () use ($ticket, $body, $isInternal, $fileIds, $admin): SupportTicketMessage {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check terminal under lock — defends against a status
            // race between the read above and this point.
            if (! $isInternal && $locked->status->isTerminal()) {
                throw TicketTerminalException::cannotReplyToTerminal();
            }

            /** @var SupportTicketMessage $message */
            $message = SupportTicketMessage::query()->create([
                'support_ticket_id' => $locked->id,
                'sender_type' => SupportTicketMessageSenderType::Admin->value,
                'sender_user_id' => (int) $admin->id,
                'body' => $body,
                'is_internal_note' => $isInternal,
            ]);

            foreach ($fileIds as $fileId) {
                SupportTicketAttachment::query()->create([
                    'support_ticket_id' => $locked->id,
                    'support_ticket_message_id' => $message->id,
                    'file_id' => $fileId,
                    'uploaded_by_user_id' => (int) $admin->id,
                ]);
            }

            $update = ['last_message_at' => $message->created_at];
            if ($locked->first_response_at === null && ! $isInternal) {
                $update['first_response_at'] = $message->created_at;
            }
            $locked->forceFill($update)->save();

            $ticketId = (int) $locked->id;
            $messageId = (int) $message->id;
            $actorId = (int) $admin->id;
            $isInternalCopy = $isInternal;
            DB::afterCommit(static function () use ($ticketId, $messageId, $isInternalCopy, $actorId): void {
                event(new SupportTicketReplied($ticketId, $messageId, $isInternalCopy, $actorId));
            });

            return $message;
        });

        return $message;
    }

    /**
     * Dispatch the matching domain event for an admin-driven status
     * transition. Wrapped in DB::afterCommit so the event only fires
     * once the row update commits.
     *
     * @param  array<string, mixed>  $update
     */
    private function dispatchTransitionEvent(
        SupportTicket $ticket,
        SupportTicketStatus $target,
        User $admin,
        array $update,
    ): void {
        $ticketId = (int) $ticket->id;
        $userId = (int) $ticket->user_id;
        $actorId = (int) $admin->id;

        switch ($target) {
            case SupportTicketStatus::Assigned:
                $assigneeId = isset($update['assigned_to_user_id']) && is_numeric($update['assigned_to_user_id'])
                    ? (int) $update['assigned_to_user_id']
                    : (int) $ticket->assigned_to_user_id;
                DB::afterCommit(static function () use ($ticketId, $assigneeId, $actorId): void {
                    event(new SupportTicketAssigned($ticketId, $assigneeId, $actorId));
                });
                break;

            case SupportTicketStatus::InProgress:
                DB::afterCommit(static function () use ($ticketId, $actorId): void {
                    event(new SupportTicketInProgress($ticketId, $actorId));
                });
                break;

            case SupportTicketStatus::Resolved:
                $summary = isset($update['resolution_summary']) && is_string($update['resolution_summary'])
                    ? $update['resolution_summary']
                    : null;
                DB::afterCommit(static function () use ($ticketId, $summary, $actorId): void {
                    event(new SupportTicketResolved($ticketId, $summary, $actorId));
                });
                break;

            case SupportTicketStatus::Closed:
                DB::afterCommit(static function () use ($ticketId, $actorId): void {
                    event(new SupportTicketClosed($ticketId, $actorId));
                });
                break;

            case SupportTicketStatus::Cancelled:
                $reason = isset($update['cancellation_reason']) && is_string($update['cancellation_reason'])
                    ? $update['cancellation_reason']
                    : null;
                DB::afterCommit(static function () use ($ticketId, $userId, $reason, $actorId): void {
                    event(new SupportTicketCancelled($ticketId, $userId, $reason, $actorId));
                });
                break;

            case SupportTicketStatus::Open:
                // Initial state — never reached via transitionTo() (the
                // canTransitionTo guard would have rejected). Silently
                // no-op for safety.
                break;
        }
    }
}
