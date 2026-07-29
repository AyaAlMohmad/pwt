<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Banner;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SupportTicket;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22 — Admin audit-log reader.
 *
 * Read-only surface over Spatie's `activity_log` table. The audit log is
 * append-only — this service exposes no write methods. The PUT template
 * editor writes its own activity row from
 * {@see NotificationTemplateService::update()}.
 *
 * The query uses LEFT JOIN against `users` / `model_has_roles` / `roles`
 * because soft-deleted causers must still surface in the audit trail with
 * their last-known name + email + role. Eloquent SoftDeletes default would
 * silently filter trashed users out — see § 9.11 of the architecture doc.
 *
 * `action` is treated as an alias for `log_name`; if both are present
 * `action` wins. `subject_type` accepts either a fully-qualified class
 * name or one of the short aliases in {@see self::SUBJECT_ALIASES} —
 * resolved here, so admins don't have to type FQNs.
 *
 * See docs/architecture/admin-audit-templates-dashboard.md.
 */
final class AuditLogService
{
    /**
     * Short alias → FQN map for the `subject_type` filter.
     *
     * @var array<string, class-string>
     */
    private const SUBJECT_ALIASES = [
        'order' => Order::class,
        'maintenance_request' => MaintenanceRequest::class,
        'machine' => Machine::class,
        'product' => Product::class,
        'user' => User::class,
        'support_ticket' => SupportTicket::class,
        'banner' => Banner::class,
        'promotion' => Promotion::class,
        'testimonial' => Testimonial::class,
        'notification_template' => NotificationTemplate::class,
    ];

    /**
     * Paginated audit-log read.
     *
     * Sort is FIXED to `created_at DESC, id DESC` — admins should never be
     * able to scan ancient rows ASC, which always full-scans the table.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $table = (string) config('activitylog.table_name', 'activity_log');

        /** @var Builder $query */
        $query = DB::table($table)
            ->leftJoin('users', 'users.id', '=', $table.'.causer_id')
            ->leftJoin('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', '=', User::class);
            })
            ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select([
                $table.'.id',
                $table.'.log_name',
                $table.'.description',
                $table.'.event',
                $table.'.subject_type',
                $table.'.subject_id',
                $table.'.causer_type',
                $table.'.causer_id',
                $table.'.properties',
                $table.'.created_at',
                'users.name as causer_name',
                'users.email as causer_email',
                'roles.name as causer_role',
            ]);

        $this->applyFilters($query, $filters, $table);

        $query->orderBy($table.'.created_at', 'desc')
            ->orderBy($table.'.id', 'desc');

        return $this->paginate($query, $page, $perPage);
    }

    /**
     * Resolve a short alias to its FQN, or pass through if already FQN.
     */
    private function resolveSubjectType(string $raw): string
    {
        $normalised = strtolower(trim($raw));

        return self::SUBJECT_ALIASES[$normalised] ?? $raw;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, string $table): void
    {
        // `action` is an alias for `log_name`; when both present, action wins.
        $logName = null;
        if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
            $logName = $filters['action'];
        } elseif (isset($filters['log_name']) && is_string($filters['log_name']) && $filters['log_name'] !== '') {
            $logName = $filters['log_name'];
        }
        if ($logName !== null) {
            $query->where($table.'.log_name', $logName);
        }

        if (isset($filters['event']) && is_string($filters['event']) && $filters['event'] !== '') {
            $query->where($table.'.event', $filters['event']);
        }

        if (isset($filters['actor_id']) && is_numeric($filters['actor_id'])) {
            $query->where($table.'.causer_id', (int) $filters['actor_id']);
        }

        if (isset($filters['subject_type']) && is_string($filters['subject_type']) && $filters['subject_type'] !== '') {
            $query->where($table.'.subject_type', $this->resolveSubjectType($filters['subject_type']));
        }

        if (isset($filters['subject_id']) && is_numeric($filters['subject_id'])) {
            $query->where($table.'.subject_id', (int) $filters['subject_id']);
        }

        if (isset($filters['date_from']) && is_string($filters['date_from']) && $filters['date_from'] !== '') {
            $query->where($table.'.created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to']) && is_string($filters['date_to']) && $filters['date_to'] !== '') {
            $query->where($table.'.created_at', '<=', $filters['date_to'].' 23:59:59');
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $like = '%'.trim($filters['search']).'%';
            $query->where($table.'.description', 'LIKE', $like);
        }
    }

    /**
     * Hand-rolled paginate over a Query Builder — needed because we are
     * not using Eloquent (no `AuditLog` model), and the default
     * `Builder::paginate()` works fine but we want explicit page resolver
     * control so tests can drive the page param.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function paginate(Builder $query, int $page, int $perPage): LengthAwarePaginator
    {
        Paginator::currentPageResolver(static fn (): int => $page);

        $total = (int) (clone $query)
            ->select(DB::raw('1'))
            ->getCountForPagination();

        $items = $query
            ->forPage($page, $perPage)
            ->get()
            ->all();

        return new ConcreteLengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }
}
