<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * Ownership-only authorisation for {@see Order}.
 *
 * Customer endpoints scope by `user_id = auth.id`. Admin-side order ops
 * land in Phase 17 with their own `*-any` permission keys
 * (`orders.view-any`, `orders.update-any`, `orders.cancel-any`).
 *
 * Per CLAUDE.md § 7.3 the ownership failure surfaces as **403 not 404**
 * — Laravel's AuthorizationException is shaped by the global handler
 * (see bootstrap/app.php).
 */
final class OrderPolicy
{
    /**
     * Any authenticated customer may list their OWN orders. The
     * controller scopes the query to `user_id = auth.id` regardless.
     */
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Order $order): bool
    {
        return $order->user_id === $actor->id;
    }

    /**
     * Order creation is gated by Sanctum auth only — no additional
     * permission key for customers (per CLAUDE.md § 7.4: customer
     * role carries no admin permission keys).
     */
    public function create(User $actor): bool
    {
        return true;
    }

    public function cancel(User $actor, Order $order): bool
    {
        return $order->user_id === $actor->id;
    }

    public function track(User $actor, Order $order): bool
    {
        return $order->user_id === $actor->id;
    }

    /**
     * Reserved for Phase 17 admin endpoints. Customer surface never
     * touches this method.
     */
    public function update(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.update-status');
    }

    /*
    |--------------------------------------------------------------------------
    | Admin abilities (Phase 17)
    |--------------------------------------------------------------------------
    | Permission-keyed gates for the admin-orders module. Ownership is
    | intentionally NOT checked — admins act on any customer's order.
    | The `admin` middleware (Phase 14) is the role gate; these methods
    | layer the finer-grained Spatie permission check on top.
    |
    | See docs/architecture/admin-orders.md § 6.2.
    */

    /**
     * GET /api/v1/admin/orders — admin list across all customers.
     */
    public function viewAnyAdmin(User $actor): bool
    {
        return $actor->can('orders.view');
    }

    /**
     * GET /api/v1/admin/orders/{id} — admin single-order detail.
     */
    public function viewAdmin(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.view');
    }

    /**
     * PATCH /api/v1/admin/orders/{id}/status — drive the state machine.
     */
    public function updateStatusAdmin(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.update-status');
    }

    /**
     * POST /api/v1/admin/orders/{id}/cancel — admin cancel override.
     */
    public function cancelAdmin(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.cancel-any');
    }

    /**
     * POST /api/v1/admin/orders/{id}/schedule-installation.
     */
    public function scheduleAdmin(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.schedule');
    }

    /**
     * POST /api/v1/admin/orders/{id}/notes — internal admin notes.
     * Shares the update-status permission key (a note is part of the
     * same operational capability surface).
     */
    public function addNoteAdmin(User $actor, Order $order): bool
    {
        unset($order);

        return $actor->can('orders.update-status');
    }

    /**
     * POST /api/v1/admin/orders — admin manual order creation.
     */
    public function createAdmin(User $actor): bool
    {
        return $actor->can('orders.create');
    }

}
