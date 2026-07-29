<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\InstallationStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Exceptions\Orders\PaymentNotCapturedException;
use App\Models\Order;
use App\Models\OrderInstallation;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Orders\OrderStatusTransitionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Admin-side orders orchestrator.
 *
 * Does NOT define a new state machine — every status mutation flows
 * through Phase 8's {@see OrderStatusTransitionService} with the
 * admin actor stamped on the resulting `order_status_history` row.
 *
 * Cross-cutting concerns:
 *  - All write methods wrap the underlying transition + side-effect
 *    in `DB::transaction(...)` so the row-level lock from
 *    {@see OrderStatusTransitionService} extends across the auxiliary
 *    mutations (e.g. order_installations upsert).
 *  - Every successful admin action emits a Spatie ActivityLog entry
 *    under the `admin.orders` log name. The Order model's
 *    {@see LogsActivity} trait already
 *    captures the before/after diff on persisted fields; the
 *    explicit `activity()` calls here capture the actor + IP context
 *    that the trait cannot.
 *
 * See docs/architecture/admin-orders.md § 10.1.
 */
final class AdminOrderService
{
    /**
     * Whitelisted sort columns, mapped to actual DB columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'placed_at' => 'created_at',
        'total_amount' => 'total_amount',
        'status' => 'status',
    ];

    public function __construct(
        private readonly OrderStatusTransitionService $transitions,
    ) {}

    /**
     * Count orders grouped by status. Returns zero counts for every
     * defined status so the frontend dashboard chart doesn't need
     * null-guards.
     */
    public function countByStatus(): array
    {
        /** @var \Illuminate\Support\Collection<int, object{status: string, count: int}> $rows */
        $rows = \Illuminate\Support\Facades\DB::table('orders')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get();

        /** @var array<string, int> $counts */
        $counts = $rows->mapWithKeys(static function ($row): array {
            return [(string) $row->status => (int) $row->count];
        })->all();

        // Ensure every status appears (zero-fill for absent ones).
        $result = [];
        foreach (OrderStatus::cases() as $status) {
            $result[$status->value] = $counts[$status->value] ?? 0;
        }

        return $result;
    }

    /**
     * Paginated list across ALL orders with the standard eager loads.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Order> $query */
        $query = $includeTrashed
            ? Order::query()->withTrashed()
            : Order::query();

        $query
            ->with([
                'user:id,name,email,phone,phone_country_code',
                'deliveryAddress:id,user_id,country_id,city,label',
                'deliveryAddress.country:id,code',
                'payments',
                'promotion',
                'items:id,order_id,product_id,product_code,product_name_snapshot,term,quantity,unit_price,currency',
                'items.product:id,code,name',
            ])
            ->withCount('items');

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Full-detail load including soft-deleted rows so admins can audit
     * trashed orders. Throws ModelNotFoundException → 404 when absent.
     */
    public function find(int $id): Order
    {
        /** @var Order $order */
        $order = Order::query()
            ->withTrashed()
            ->with([
                'user:id,name,email,phone,phone_country_code,locale,is_active,last_login_at,deleted_at',
                'items',
                'deliveryAddress.country',
                'deliveryAddress.region',
                'billingAddress.country',
                'billingAddress.region',
                'promotion',
                'statusHistory.actor:id,name',
                'installation.technician:id,name',
                'payments.events',
                'cancelledBy:id,name',
            ])
            ->withCount('items')
            ->findOrFail($id);

        if ($order->user !== null) {
            $order->user->loadCount(['orders', 'supportTickets', 'machines']);
        }

        return $order;
    }

    /**
     * Drive the state machine. Delegates to
     * {@see OrderStatusTransitionService::transition()}; rejects
     * `status === scheduled` so admins are funnelled through the
     * dedicated schedule-installation endpoint.
     */
    public function transitionStatus(
        Order $order,
        OrderStatus $target,
        User $actor,
        ?string $note,
        ?string $ip = null,
    ): Order {
        if ($target === OrderStatus::Scheduled) {
            throw InvalidOrderStateException::useScheduleEndpoint();
        }

        $transitioned = DB::transaction(function () use ($order, $target, $actor, $note): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->withTrashed()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Online-payment orders must have a captured payment before the
            // admin can move them out of pending (except cancellation).
            if (
                $locked->status === OrderStatus::Pending
                && $target !== OrderStatus::Cancelled
                && $locked->payment_method->isOnline()
            ) {
                $latestPayment = $locked->latestPayment();
                if ($latestPayment === null || $latestPayment->status !== PaymentStatus::Captured) {
                    throw PaymentNotCapturedException::make();
                }
            }

            $updated = $this->transitions->transition(
                order: $locked,
                target: $target,
                actorType: OrderActorType::Admin,
                actor: $actor,
                note: $this->normalizeNote($note, 1000),
            );

            return $updated;
        });

        $this->logActivity(
            event: 'admin.order.status_updated',
            description: 'Admin updated order status',
            order: $transitioned,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'to_status' => $target->value,
                'note' => $note,
            ],
        );

        return $this->reloadDetail($transitioned);
    }

    /**
     * Admin cancel — broader source-state window than the customer
     * cancel ({@see OrderStatus::isCustomerCancellable()}).
     *
     * Allowed sources per orchestrator decision 2: `pending`, `confirmed`,
     * `scheduled`, `out_for_delivery`. `installed` is excluded — once
     * machines have been created the cancellation rollback is not
     * automated (Phase 2 work); admins must go installed → completed
     * and process the refund out-of-band.
     */
    public function cancel(Order $order, User $actor, string $reason, ?string $ip = null): Order
    {
        $cancelled = DB::transaction(function () use ($order, $actor, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->withTrashed()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allowedSources = [
                OrderStatus::Pending,
                OrderStatus::Confirmed,
                OrderStatus::Scheduled,
                OrderStatus::OutForDelivery,
            ];

            if (! in_array($locked->status, $allowedSources, true)) {
                throw InvalidStatusTransitionException::from(
                    $locked->status,
                    OrderStatus::Cancelled,
                );
            }

            $locked->cancellation_reason = $reason;
            $locked->cancelled_by_user_id = $actor->id;
            $locked->save();

            return $this->transitions->transition(
                order: $locked,
                target: OrderStatus::Cancelled,
                actorType: OrderActorType::Admin,
                actor: $actor,
                note: Str::limit($reason, 980, '…'),
            );
        });

        $this->logActivity(
            event: 'admin.order.cancelled',
            description: 'Admin cancelled order',
            order: $cancelled,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'reason' => $reason,
            ],
        );

        return $this->reloadDetail($cancelled);
    }

    /**
     * Schedule the installation appointment. Requires the source state
     * to be `confirmed`; transitions `confirmed → scheduled` AND
     * inserts/updates the `order_installations` row in the same DB
     * transaction.
     */
    public function scheduleInstallation(
        Order $order,
        ?Carbon $scheduledFor,
        ?int $technicianUserId,
        ?string $installationNotes,
        User $actor,
        ?string $ip = null,
    ): Order {
        $scheduled = DB::transaction(function () use (
            $order,
            $scheduledFor,
            $technicianUserId,
            $installationNotes,
            $actor,
        ): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->withTrashed()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== OrderStatus::Confirmed) {
                throw InvalidOrderStateException::scheduleRequiresConfirmed($locked->status);
            }

            // Mirror scheduled_for_at onto the order row BEFORE the
            // transition so applySideEffects(Scheduled) sees a populated
            // value (the Phase 8 service leaves the column to Phase 17).
            if ($scheduledFor !== null) {
                $locked->scheduled_for_at = $scheduledFor;
                $locked->save();
            }

            $transitioned = $this->transitions->transition(
                order: $locked,
                target: OrderStatus::Scheduled,
                actorType: OrderActorType::Admin,
                actor: $actor,
                note: null,
            );

            // Freeze the delivery address as the installation snapshot
            // so later renames / soft-deletes don't mutate history.
            $deliveryAddress = $transitioned->deliveryAddress;
            $snapshot = $deliveryAddress !== null
                ? [
                    'id' => $deliveryAddress->id,
                    'label' => $deliveryAddress->label,
                    'recipient_name' => $deliveryAddress->recipient_name,
                    'recipient_phone' => $deliveryAddress->recipient_phone,
                    'recipient_phone_country_code' => $deliveryAddress->recipient_phone_country_code,
                    'line1' => $deliveryAddress->line1,
                    'line2' => $deliveryAddress->line2,
                    'city' => $deliveryAddress->city,
                    'area' => $deliveryAddress->area,
                    'postal_code' => $deliveryAddress->postal_code,
                    'latitude' => $deliveryAddress->latitude,
                    'longitude' => $deliveryAddress->longitude,
                ]
                : null;

            OrderInstallation::query()->updateOrCreate(
                ['order_id' => $transitioned->id],
                [
                    'scheduled_for_at' => $scheduledFor,
                    'technician_user_id' => $technicianUserId,
                    'installation_notes' => $installationNotes,
                    'address_snapshot' => $snapshot,
                    'status' => InstallationStatus::Scheduled->value,
                ],
            );

            return $transitioned;
        });

        $this->logActivity(
            event: 'admin.order.installation_scheduled',
            description: 'Admin scheduled installation',
            order: $scheduled,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'scheduled_for_at' => $scheduledFor?->toIso8601String(),
                'technician_user_id' => $technicianUserId,
            ],
        );

        return $this->reloadDetail($scheduled);
    }

    /**
     * Write a notes-only `order_status_history` row marked
     * `is_internal=true` so the customer surface never sees it.
     *
     * Rejected on terminal orders to keep the audit log hygienic.
     */
    public function addNote(Order $order, string $note, User $actor, ?string $ip = null): OrderStatusHistory
    {
        if ($order->status->isTerminal()) {
            throw InvalidOrderStateException::notesOnTerminal($order->status);
        }

        $entry = DB::transaction(function () use ($order, $note, $actor): OrderStatusHistory {
            /** @var Order $locked */
            $locked = Order::query()
                ->withTrashed()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw InvalidOrderStateException::notesOnTerminal($locked->status);
            }

            $current = $locked->status->value;

            /** @var OrderStatusHistory $row */
            $row = OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => $current,
                'to_status' => $current,
                'actor_type' => OrderActorType::Admin->value,
                'actor_id' => $actor->id,
                'note' => $note,
                'is_internal' => true,
            ]);

            return $row;
        });

        $this->logActivity(
            event: 'admin.order.note_added',
            description: 'Admin added an internal order note',
            order: $order,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'history_id' => $entry->id,
            ],
        );

        return $entry->fresh(['actor:id,name']) ?? $entry;
    }

    /**
     * Apply the parsed query-string filters to the list builder.
     *
     * @param  Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        /** @var list<string>|null $status */
        $status = isset($filters['status']) && is_array($filters['status'])
            ? array_values(array_filter(
                $filters['status'],
                static fn ($v): bool => is_string($v) && $v !== '',
            ))
            : null;
        if ($status !== null && $status !== []) {
            $query->whereIn('status', $status);
        }

        $search = isset($filters['search']) && is_string($filters['search'])
            ? trim($filters['search'])
            : null;
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->whereHas('user', static function (Builder $u) use ($like): void {
                    $u->where('name', 'LIKE', $like)
                        ->orWhere('email', 'LIKE', $like);
                })->orWhere('public_id', 'LIKE', $like);
            });
        }

        if (isset($filters['date_from']) && is_string($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to']) && is_string($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (isset($filters['payment_method']) && is_string($filters['payment_method']) && $filters['payment_method'] !== '') {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['country']) && is_string($filters['country']) && $filters['country'] !== '') {
            $code = $filters['country'];
            $query->whereHas('deliveryAddress.country', static function (Builder $c) use ($code): void {
                $c->where('code', $code);
            });
        }
    }

    /**
     * @param  Builder<Order>  $query
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
     * Reload the order with the full admin-detail eager-load profile so
     * write responses always return the canonical detail shape.
     */
    private function reloadDetail(Order $order): Order
    {
        return $this->find((int) $order->id);
    }

    /**
     * Trim notes to the persisted column width (DB max 2000; transition
     * notes default to 1000).
     */
    private function normalizeNote(?string $note, int $max): ?string
    {
        if ($note === null) {
            return null;
        }
        $trimmed = trim($note);
        if ($trimmed === '') {
            return null;
        }

        return Str::limit($trimmed, $max, '…');
    }

    /**
     * Write a Spatie ActivityLog row under the `admin.orders` log name.
     *
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        string $event,
        string $description,
        Order $order,
        User $actor,
        array $properties,
    ): void {
        activity('admin.orders')
            ->event($event)
            ->causedBy($actor)
            ->performedOn($order)
            ->withProperties($properties)
            ->log($description);
    }
}
