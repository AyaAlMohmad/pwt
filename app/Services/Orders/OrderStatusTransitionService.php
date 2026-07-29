<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Events\BaseDomainEvent;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCompleted;
use App\Events\Orders\OrderConfirmed;
use App\Events\Orders\OrderInstalled;
use App\Events\Orders\OrderOutForDelivery;
use App\Events\Orders\OrderScheduled;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Maintenance\MachineCreationFromOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * State machine for {@see Order} status transitions.
 *
 * Every transition is wrapped in `DB::transaction` with
 * `SELECT ... FOR UPDATE` on the order row so concurrent attempts (e.g.
 * web + mobile cancel of the same order, or webhook double-delivery)
 * are serialised. Invalid transitions throw
 * {@see InvalidStatusTransitionException} → 409.
 *
 * Each transition writes a row to `order_status_history` (customer-
 * visible audit) inside the same transaction; LogsActivity on the
 * model handles the cross-cutting admin audit log.
 *
 * Cross-phase side-effects (machine creation on installed, payment
 * intent cancel on cancelled) are TODO-flagged for Phase 9 / Phase 10
 * via inline `// Phase X:` markers.
 *
 * See docs/architecture/orders.md § 2.3 / § 8.8.
 */
final class OrderStatusTransitionService
{
    /**
     * Generic transition entry point. The target status must be one of
     * {@see OrderStatus::transitionsAllowed()}; otherwise a 409 is
     * thrown.
     *
     * @return Order The fresh order row after the transition.
     */
    public function transition(
        Order $order,
        OrderStatus $target,
        OrderActorType $actorType,
        ?User $actor,
        ?string $note,
    ): Order {
        return DB::transaction(function () use ($order, $target, $actorType, $actor, $note): Order {
            // Pessimistic re-read to serialise concurrent callers.
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;
            if (! $from->canTransitionTo($target)) {
                throw InvalidStatusTransitionException::from($from, $target);
            }

            $this->applySideEffects($locked, $target);
            $locked->status = $target;
            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $target->value,
                'actor_type' => $actorType->value,
                'actor_id' => $actor?->getAuthIdentifier(),
                'note' => $note,
            ]);

            // Phase 11 — dispatch the matching domain event AFTER the
            // parent transaction commits. `Completed` deliberately has
            // no notification event in MVP (no customer-facing copy).
            $event = $this->buildEventFor($target, $locked, $actor, $note);
            if ($event !== null) {
                DB::afterCommit(static function () use ($event): void {
                    event($event);
                });
            }

            return $locked->refresh();
        });
    }

    /**
     * Build the Phase 11 notification event for a given status target.
     * Returns null when the target has no customer-facing notification
     * (e.g. `Completed` is silent in MVP).
     */
    private function buildEventFor(
        OrderStatus $target,
        Order $order,
        ?User $actor,
        ?string $note,
    ): ?BaseDomainEvent {
        $actorId = $actor?->getAuthIdentifier();
        $actorIdInt = is_numeric($actorId) ? (int) $actorId : null;

        return match ($target) {
            OrderStatus::Confirmed => new OrderConfirmed((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            OrderStatus::Scheduled => new OrderScheduled((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            OrderStatus::OutForDelivery => new OrderOutForDelivery((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            OrderStatus::Installed => new OrderInstalled((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            OrderStatus::Completed => new OrderCompleted((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            OrderStatus::Cancelled => new OrderCancelled((int) $order->id, (int) $order->user_id, $actorIdInt, $note),
            default => null,
        };
    }

    /**
     * Set the status-specific timestamp columns and other side-effect
     * fields BEFORE the status mutation persists. The caller wraps this
     * call in the same `DB::transaction` as the status update.
     */
    private function applySideEffects(Order $order, OrderStatus $target): void
    {
        $now = now();

        switch ($target) {
            case OrderStatus::Confirmed:
                if ($order->confirmed_at === null) {
                    $order->confirmed_at = $now;
                }
                // Phase 9 / Phase 17: write `promotion_redemptions` row here
                // inside the transaction (the engine's read-only checks are
                // not enough for the over_total_limit race window — the
                // confirm path must SELECT ... FOR UPDATE on the promotion
                // and re-evaluate. Customer Phase 8 never confirms; this
                // branch executes only via Phase 9 webhook / Phase 17 admin.
                break;

            case OrderStatus::Scheduled:
                // scheduled_for_at is set by the Phase 17 admin endpoint
                // alongside the order_installations row.
                break;

            case OrderStatus::OutForDelivery:
                // No timestamp column for this step in MVP.
                break;

            case OrderStatus::Installed:
                if ($order->installed_at === null) {
                    $order->installed_at = $now;
                }
                // Phase 10: auto-create Machine row(s) from the order's
                // installed items. We persist installed_at first so the
                // creation service sees the canonical timestamp; both
                // writes share the parent DB::transaction so they commit
                // atomically.
                $order->save();
                $machines = app(MachineCreationFromOrderService::class)
                    ->createFromOrder($order);
                Log::debug('Order→Machine: created machines on install.', [
                    'order_id' => $order->id,
                    'machines_created' => count($machines),
                ]);
                break;

            case OrderStatus::Completed:
                if ($order->completed_at === null) {
                    $order->completed_at = $now;
                }
                break;

            case OrderStatus::Cancelled:
                if ($order->cancelled_at === null) {
                    $order->cancelled_at = $now;
                }
                break;

            case OrderStatus::Pending:
                // Pending is the create-state; never a transition target.
                break;
        }
    }
}
