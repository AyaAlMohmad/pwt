<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\MachineSource;
use App\Exceptions\Maintenance\InvalidMaintenanceTransitionException;
use App\Models\Machine;
use App\Models\User;
use App\Services\Maintenance\MaintenanceScheduler;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Admin-side machines orchestrator.
 *
 * Cross-cutting:
 *  - All write methods wrap in `DB::transaction` for atomicity.
 *  - Every successful admin action emits a Spatie ActivityLog entry
 *    under the `admin.machines` log name. The Machine model's
 *    {@see LogsActivity} trait already captures the diff on persisted
 *    fields; the explicit `activity()` writes here capture actor + IP
 *    context the trait cannot.
 *
 * See docs/architecture/admin-machines-maintenance.md § 10.1.
 */
final class AdminMachineService
{
    /**
     * Whitelisted sort columns -> actual DB columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'installed_at' => 'installed_at',
        'next_maintenance_due_at' => 'next_maintenance_due_at',
        'serial_number' => 'serial_number',
    ];

    public function __construct(
        private readonly MaintenanceScheduler $scheduler,
    ) {}

    /**
     * Paginated list across ALL machines.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Machine>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Machine> $query */
        $query = $includeTrashed
            ? Machine::query()->withTrashed()
            : Machine::query();

        $query
            ->with([
                'user:id,name,email,phone,phone_country_code',
                'product:id,code,name,individual_buy_enabled',
                'address:id,user_id,country_id,city,label',
                'address.country:id,code',
                'order:id,public_id,status',
            ])
            ->with($this->customerAddressEagerLoad())
            ->withCount(['maintenanceRequests as open_requests_count' => function (Builder $q): void {
                $q->whereIn('status', ['new', 'assigned', 'in_progress']);
            }]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Convenience listing for the "due maintenance" widget. Forces
     * `next_maintenance_due_at ASC, id ASC` ordering.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Machine>
     */
    public function dueMaintenance(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $withinDays = isset($filters['within_days']) && is_numeric($filters['within_days'])
            ? (int) $filters['within_days']
            : (isset($filters['days']) && is_numeric($filters['days']) ? (int) $filters['days'] : 30);

        $query = $this->scheduler->dueWithinDays($withinDays)
            ->with([
                'user:id,name,email,phone,phone_country_code',
                'product:id,code,name,individual_buy_enabled',
                'address:id,user_id,country_id,city,label',
                'address.country:id,code',
            ])
            ->with($this->customerAddressEagerLoad())
            ->withCount(['maintenanceRequests as open_requests_count' => function (Builder $q): void {
                $q->whereIn('status', ['new', 'assigned', 'in_progress']);
            }]);

        if (isset($filters['country_code']) && is_string($filters['country_code']) && $filters['country_code'] !== '') {
            $query->where('country_code', strtolower($filters['country_code']));
        }
        if (isset($filters['customer_id']) && is_numeric($filters['customer_id'])) {
            $query->where('user_id', (int) $filters['customer_id']);
        }

        $query->orderBy('next_maintenance_due_at', 'asc')->orderBy('id', 'asc');

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Full-detail load including soft-deleted rows.
     */
    public function find(int $id): Machine
    {
        /** @var Machine $machine */
        $machine = Machine::query()
            ->withTrashed()
            ->with([
                'user:id,name,email,phone,phone_country_code,locale,is_active,deleted_at,last_login_at',
                'product:id,code,name,individual_buy_enabled',
                'address.country',
                'order:id,public_id,status',
                'maintenanceLogs' => function ($q): void {
                    $q->with(['technician:id,name', 'createdBy:id,name'])->limit(50);
                },
                'maintenanceRequests' => function ($q): void {
                    $q->with(['assignedTo:id,name'])->limit(20);
                },
            ])
            ->with($this->customerAddressEagerLoad())
            ->withCount([
                'maintenanceLogs as total_logs_count',
                'maintenanceRequests as open_requests_count' => function (Builder $q): void {
                    $q->whereIn('status', ['new', 'assigned', 'in_progress']);
                },
            ])
            ->findOrFail($id);

        return $machine;
    }

    /**
     * Create a machine. `source` is forced to `created`; `order_id` /
     * `order_installation_id` are forced to null.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor, ?string $ip = null): Machine
    {
        $machine = DB::transaction(function () use ($payload): Machine {
            $countryCode = isset($payload['country_code']) && is_string($payload['country_code'])
                ? strtolower($payload['country_code'])
                : null;

            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true;
            $individualBuyEnabled = array_key_exists('individual_buy_enabled', $payload) ? (bool) $payload['individual_buy_enabled'] : true;

            /** @var Machine $machine */
            $machine = Machine::query()->create([
                'user_id' => (int) $payload['user_id'],
                'product_id' => (int) $payload['product_id'],
                'serial_number' => (string) $payload['serial_number'],
                'term' => (string) $payload['term'],
                'installed_at' => (string) $payload['installed_at'],
                'filter_change_interval_months' => isset($payload['filter_change_interval_months'])
                    ? (int) $payload['filter_change_interval_months']
                    : null,
                'next_maintenance_due_at' => isset($payload['next_maintenance_due_at']) && is_string($payload['next_maintenance_due_at']) && $payload['next_maintenance_due_at'] !== ''
                    ? (string) $payload['next_maintenance_due_at']
                    : null,
                'country_code' => (string) $countryCode,
                'voltage' => isset($payload['voltage']) && is_string($payload['voltage']) ? $payload['voltage'] : null,
                'is_active' => $isActive,
                'individual_buy_enabled' => $individualBuyEnabled,
                'source' => MachineSource::Created->value,
                'order_id' => null,
                'order_installation_id' => null,
            ]);

            // Auto-compute next_maintenance_due_at when interval is set
            // AND admin did not pass an explicit override.
            $hasExplicitNext = isset($payload['next_maintenance_due_at'])
                && is_string($payload['next_maintenance_due_at'])
                && $payload['next_maintenance_due_at'] !== '';
            if (! $hasExplicitNext && $machine->filter_change_interval_months !== null) {
                $next = $this->scheduler->computeNextDue($machine);
                if ($next !== null) {
                    $machine->update(['next_maintenance_due_at' => $next->toDateString()]);
                }
            }

            return $machine;
        });

        $this->logActivity(
            event: 'admin.machine.created',
            description: 'Admin created machine',
            machine: $machine,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'serial_number' => $machine->serial_number,
                'user_id' => $machine->user_id,
                'product_id' => $machine->product_id,
            ],
        );

        return $this->find((int) $machine->id);
    }

    /**
     * Update a machine. Rejects trashed rows with 409. Recomputes
     * `next_maintenance_due_at` when `filter_change_interval_months`
     * changes AND no explicit override is supplied.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Machine $machine, array $payload, User $actor, ?string $ip = null): Machine
    {
        if ($machine->trashed()) {
            throw InvalidMaintenanceTransitionException::resourceTrashed('admin.machines.resource_trashed');
        }

        $beforeOwnerId = (int) $machine->user_id;
        $beforeProductId = (int) $machine->product_id;

        $updated = DB::transaction(function () use ($machine, $payload): Machine {
            /** @var Machine $locked */
            $locked = Machine::query()
                ->whereKey($machine->id)
                ->lockForUpdate()
                ->firstOrFail();

            $originalInterval = $locked->filter_change_interval_months;
            $hasExplicitNext = array_key_exists('next_maintenance_due_at', $payload);
            $hasInterval = array_key_exists('filter_change_interval_months', $payload);

            $attributes = [];
            foreach ([
                'serial_number',
                'term',
                'installed_at',
                'location_label',
                'notes',
                'voltage',
                'source',
            ] as $key) {
                if (array_key_exists($key, $payload)) {
                    $attributes[$key] = $payload[$key];
                }
            }
            foreach (['user_id', 'product_id', 'address_id'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $attributes[$key] = (int) $payload[$key];
                }
            }
            // Nullable FKs — preserve an explicit null instead of casting
            // it to 0, which would break the foreign key.
            foreach (['order_id', 'order_installation_id'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $attributes[$key] = $payload[$key] !== null ? (int) $payload[$key] : null;
                }
            }
            if (array_key_exists('is_active', $payload)) {
                $attributes['is_active'] = (bool) $payload['is_active'];
            }
            if (array_key_exists('individual_buy_enabled', $payload)) {
                $attributes['individual_buy_enabled'] = (bool) $payload['individual_buy_enabled'];
            }
            if (array_key_exists('country_code', $payload) && is_string($payload['country_code'])) {
                $attributes['country_code'] = strtolower($payload['country_code']);
            }
            if ($hasInterval) {
                $attributes['filter_change_interval_months'] = $payload['filter_change_interval_months'] !== null
                    ? (int) $payload['filter_change_interval_months']
                    : null;
            }
            if ($hasExplicitNext) {
                $next = $payload['next_maintenance_due_at'];
                $attributes['next_maintenance_due_at'] = (is_string($next) && $next !== '')
                    ? $next
                    : null;
            }

            $locked->update($attributes);

            // Recompute next_maintenance_due_at when the interval changed
            // AND admin did not supply an explicit override.
            if (! $hasExplicitNext && $hasInterval && $locked->filter_change_interval_months !== $originalInterval) {
                $refreshed = $locked->fresh();
                if ($refreshed !== null) {
                    $next = $this->scheduler->computeNextDue($refreshed);
                    $locked->update(['next_maintenance_due_at' => $next?->toDateString()]);
                }
            }

            return $locked;
        });

        $this->logActivity(
            event: 'admin.machine.updated',
            description: 'Admin updated machine',
            machine: $updated,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'changes' => array_keys($payload),
            ],
        );

        // Ownership / product reassignment gets its own audit row so the
        // history of who a machine belonged to stays reconstructable.
        if ((int) $updated->user_id !== $beforeOwnerId || (int) $updated->product_id !== $beforeProductId) {
            $this->logActivity(
                event: 'admin.machine.reassigned',
                description: 'Admin reassigned machine',
                machine: $updated,
                actor: $actor,
                properties: [
                    'ip' => $ip,
                    'before_user_id' => $beforeOwnerId,
                    'after_user_id' => (int) $updated->user_id,
                    'before_product_id' => $beforeProductId,
                    'after_product_id' => (int) $updated->product_id,
                ],
            );
        }

        return $this->find((int) $updated->id);
    }

    /**
     * Eager-load spec for the owner's default address so the admin
     * `customer.address` embed resolves without an N+1. A single row is
     * fetched per owner (`is_default DESC` — the AddressService keeps the
     * flag unique per user).
     *
     * @return array<string, Closure>
     */
    private function customerAddressEagerLoad(): array
    {
        return [
            'user.addresses' => static function (HasMany $query): void {
                $query->with(['country:id,code', 'region:id,name'])
                    ->orderByDesc('is_default')
                    ->limit(1);
            },
        ];
    }

    /**
     * @param  Builder<Machine>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $like = '%'.trim($filters['search']).'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('serial_number', 'LIKE', $like)
                    ->orWhere('location_label', 'LIKE', $like)
                    ->orWhereHas('product', static function (Builder $p) use ($like): void {
                        $p->where('code', 'LIKE', $like);
                    });
            });
        }

        if (isset($filters['country_code']) && is_string($filters['country_code']) && $filters['country_code'] !== '') {
            $query->where('country_code', strtolower($filters['country_code']));
        }
        if (isset($filters['product_id']) && is_numeric($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (isset($filters['customer_id']) && is_numeric($filters['customer_id'])) {
            $query->where('user_id', (int) $filters['customer_id']);
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $termValue = match ($filters['status']) {
                'rented' => 'rent',
                'purchased' => 'buy',
                default => null,
            };
            if ($termValue !== null) {
                $query->where('term', $termValue);
            }
        }
        if (isset($filters['source']) && is_string($filters['source']) && $filters['source'] !== '') {
            $query->where('source', $filters['source']);
        }
        if (isset($filters['term']) && is_string($filters['term']) && $filters['term'] !== '') {
            $query->where('term', $filters['term']);
        }

        if (! empty($filters['needs_maintenance'])) {
            $within = isset($filters['within_days']) && is_numeric($filters['within_days'])
                ? (int) $filters['within_days']
                : 30;
            $query->whereNotNull('next_maintenance_due_at')
                ->where('next_maintenance_due_at', '<=', Carbon::now()->addDays($within)->toDateString());
        }

        if (isset($filters['date_from']) && is_string($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to']) && is_string($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }
    }

    /**
     * @param  Builder<Machine>  $query
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
        Machine $machine,
        User $actor,
        array $properties,
    ): void {
        activity('admin.machines')
            ->event($event)
            ->causedBy($actor)
            ->performedOn($machine)
            ->withProperties($properties)
            ->log($description);
    }
}
