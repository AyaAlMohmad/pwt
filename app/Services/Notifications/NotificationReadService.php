<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Read-side service for the customer notification inbox.
 *
 * Handles the three customer endpoints (list, mark-read, mark-all-read)
 * with single-purpose methods so the controller stays ≤ 30 LOC per
 * action per CLAUDE.md § 15.
 */
final class NotificationReadService
{
    /**
     * Paginated, filtered list scoped to the caller. Sort is fixed at
     * `created_at DESC` plus `id DESC` tie-breaker.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function listForUser(
        User $user,
        array $filters,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $query = Notification::query()->forUser((int) $user->id);

        if (! empty($filters['unread_only'])) {
            $query->unread();
        }

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $query->byType($filters['type']);
        }

        if (isset($filters['date_from']) && is_string($filters['date_from']) && $filters['date_from'] !== '') {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to']) && is_string($filters['date_to']) && $filters['date_to'] !== '') {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        return $query->paginate(perPage: $perPage, page: $page);
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->forUser((int) $user->id)
            ->unread()
            ->count();
    }

    /**
     * Idempotent mark-as-read. Re-marking an already-read row preserves
     * the existing `read_at` and returns the row unchanged.
     */
    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => Carbon::now()])->save();
        }

        return $notification->refresh();
    }

    /**
     * Bulk mark every unread row for the caller. Returns the number of
     * rows updated.
     */
    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->forUser((int) $user->id)
            ->unread()
            ->update(['read_at' => Carbon::now()]);
    }
}
