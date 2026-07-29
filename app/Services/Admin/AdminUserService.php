<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Exceptions\Admin\Users\CannotChangeOwnRoleException;
use App\Exceptions\Admin\Users\CannotDeactivateSelfException;
use App\Exceptions\Admin\Users\LastSuperAdminException;
use App\Models\User;
use App\Services\Addresses\AddressService;
use App\Services\Auth\AdminAuthService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side users lifecycle service.
 *
 * Carries all business logic for the four admin-users endpoints:
 * paginated list with filters, single-user detail, partial update
 * (including role changes + token revocation on is_active toggles), and
 * soft-deactivate.
 *
 * Mutations run inside `DB::transaction(...)` with `lockForUpdate()` on
 * the candidate super_admin set whenever the operation could remove
 * super_admin status — see docs/architecture/admin-users.md § 9.9.
 *
 * Every successful PATCH/DELETE writes Spatie ActivityLog rows under
 * the `admin.users` log name (CLAUDE.md § 7.5, architecture § 6.6).
 */
final class AdminUserService
{
    /**
     * Whitelisted sort columns, mapped to their actual DB column.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'last_login_at' => 'last_login_at',
        'name' => 'name',
        'email' => 'email',
    ];

    public function __construct(
        private readonly AdminAuthService $adminAuthService,
        private readonly AddressService $addressService,
    ) {}

    /**
     * Paginated user list with filters.
     *
     * Soft-deleted rows are included only when `include_trashed === true`
     * AND the caller can actually see them — the controller gates the
     * `include_trashed` flag via permission check before calling here.
     *
     * @param  array{
     *   search?: string|null,
     *   role?: string|null,
     *   is_active?: bool|null,
     *   include_trashed?: bool|null,
     *   created_from?: string|null,
     *   created_to?: string|null,
     *   sort?: string|null,
     *   order?: string|null,
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<User> $query */
        $query = $includeTrashed
            ? User::query()->withTrashed()
            : User::query();

        $query->with('roles')
            ->with(['addresses' => function ($q): void {
                $q->with('region:id,name')->orderByDesc('is_default')->limit(1);
            }])
            ->withCount(['orders', 'supportTickets', 'machines']);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(
            perPage: $perPage,
            page: $page,
        );
    }

    /**
     * List active technician users (operations role) for dropdowns.
     *
     * @return list<array{id: int, name: string, email: string|null, phone: string|null}>
     */
    public function listTechnicians(): array
    {
        /** @var list<User> $users */
        $users = User::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereHas('roles', static function (Builder $q): void {
                $q->whereIn('name', [UserRole::Operations->value, UserRole::SuperAdmin->value]);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone']);

        return $users->map(static fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone !== null ? $u->phone_country_code.$u->phone : null,
        ])->values()->all();
    }

    /**
     * Find a user by id, including soft-deleted rows so the dashboard
     * can render a "deactivated" detail view.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id, bool $includeTrashed = true): User
    {
        $query = $includeTrashed
            ? User::query()->withTrashed()
            : User::query();

        /** @var User $user */
        $user = $query
            ->with('roles')
            ->findOrFail($id);

        $user->loadCount(['orders', 'supportTickets', 'machines']);

        return $user;
    }

    /**
     * Admin creates a new user account directly (no OTP flow).
     *
     * Defaults to the customer role. Admin roles require the actor
     * to be a super_admin.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createCustomer(array $payload, User $actor, ?string $ip = null): User
    {
        $requestedRole = isset($payload['role']) && is_string($payload['role']) && $payload['role'] !== ''
            ? $payload['role']
            : UserRole::Customer->value;

        $isAdminRole = $requestedRole !== UserRole::Customer->value;
        if ($isAdminRole && ! $actor->hasRole(UserRole::SuperAdmin->value)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                __('admin.users.role_change_forbidden'),
            );
        }

        $user = DB::transaction(function () use ($payload, $requestedRole): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => (string) $payload['name'],
                'email' => isset($payload['email']) && is_string($payload['email']) ? $payload['email'] : null,
                'phone_country_code' => (string) $payload['phone_country_code'],
                'phone' => (string) $payload['phone'],
                'locale' => isset($payload['locale']) && is_string($payload['locale']) ? $payload['locale'] : config('app.locale', 'en'),
                'account_type' => (string) $payload['account_type'],
                'company_name' => isset($payload['company_name']) && is_string($payload['company_name']) ? $payload['company_name'] : null,
                'password' => \Illuminate\Support\Facades\Hash::make((string) $payload['password']),
                'is_active' => true,
            ]);

            $user->assignRole($requestedRole);

            // Create an address if the admin supplied one.
            if (isset($payload['address']) && is_array($payload['address'])) {
                $addressData = $payload['address'];
                $addressData['recipient_name'] = $addressData['recipient_name']
                    ?? $payload['name'];
                $addressData['recipient_phone'] = $addressData['recipient_phone']
                    ?? ($payload['phone'] ?? null);
                $addressData['recipient_phone_country_code'] = $addressData['recipient_phone_country_code']
                    ?? ($payload['phone_country_code'] ?? null);
                $this->addressService->create($user, $addressData);
            }

            return $user;
        });

        $this->logActivity(
            event: 'admin.user.created',
            description: 'Admin created user',
            target: $user,
            actor: $actor,
            properties: [
                'ip' => $ip,
                'account_type' => $user->account_type?->value,
                'role' => $requestedRole,
            ],
        );

        $user->load('roles');
        $user->loadCount(['orders', 'supportTickets', 'machines']);
        $user->load(['addresses.country', 'addresses.region']);

        return $user;
    }

    /**
     * Apply a partial update.
     *
     * Behaviour:
     *  - Cross-rule short-circuits (CannotChangeOwnRole /
     *    CannotDeactivateSelf) fire BEFORE the transaction so the audit
     *    log stays clean.
     *  - Role changes and `is_active=false` on a super_admin acquire a
     *    row-level lock on the remaining super_admin set and abort with
     *    LastSuperAdminException when the count would drop to zero.
     *  - `is_active` transitioning from true to false revokes all
     *    Sanctum tokens for the user (per resolved open question 9.3).
     *  - One `admin.user.updated` activity row carries the full diff;
     *    role and is_active changes each emit an additional specialised
     *    event row (1–3 rows per call).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CannotDeactivateSelfException
     * @throws CannotChangeOwnRoleException
     * @throws LastSuperAdminException
     */
    public function update(User $target, array $payload, User $actor, ?string $ip = null): User
    {
        $this->guardSelfMutations($target, $actor, $payload);

        return DB::transaction(function () use ($target, $payload, $actor, $ip): User {
            // Reload + lock the target row. lockForUpdate is on the row
            // we are about to mutate so concurrent writers serialise.
            /** @var User $locked */
            $locked = User::query()
                ->withTrashed()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();
            // Carry forward the eagerly-loaded roles relation so the
            // hasRole() check below doesn't fire a fresh query and miss
            // an in-transaction snapshot.
            $locked->setRelation('roles', $target->roles);

            $before = $this->snapshotFields($locked);
            $beforeRole = $this->resolveRoleName($locked);

            // Anticipate role/super_admin loss BEFORE applying. This
            // also covers the `is_active=false` on a sole super_admin.
            $this->guardLastSuperAdmin($locked, $payload);

            $changes = $this->applyMutableFields($locked, $payload);

            $roleChanged = false;
            $afterRole = $beforeRole;
            if (array_key_exists('role', $payload) && is_string($payload['role'])) {
                $newRole = $payload['role'];
                if ($newRole !== $beforeRole) {
                    $locked->syncRoles([$newRole]);
                    $locked->load('roles');
                    $afterRole = $newRole;
                    $roleChanged = true;
                }
            }

            $isActiveChanged = array_key_exists('is_active', $payload)
                && (bool) $before['is_active'] !== (bool) $locked->is_active;

            if ($isActiveChanged && $locked->is_active === false) {
                $locked->tokens()->delete();
            }

            $after = $this->snapshotFields($locked);

            if ($changes !== [] || $roleChanged) {
                $this->logActivity(
                    event: 'admin.user.updated',
                    description: 'Admin updated user',
                    target: $locked,
                    actor: $actor,
                    properties: [
                        'ip' => $ip,
                        'before' => $before,
                        'after' => $after,
                    ],
                );
            }

            if ($roleChanged) {
                $this->logActivity(
                    event: 'admin.user.role_changed',
                    description: 'Admin changed user role',
                    target: $locked,
                    actor: $actor,
                    properties: [
                        'ip' => $ip,
                        'before_role' => $beforeRole,
                        'after_role' => $afterRole,
                    ],
                );
            }

            if ($isActiveChanged) {
                $this->logActivity(
                    event: 'admin.user.activity_toggled',
                    description: 'Admin toggled user activity',
                    target: $locked,
                    actor: $actor,
                    properties: [
                        'ip' => $ip,
                        'before_is_active' => (bool) $before['is_active'],
                        'after_is_active' => (bool) $locked->is_active,
                    ],
                );
            }

            $locked->refresh();
            $locked->load('roles');
            $locked->loadCount(['orders', 'supportTickets', 'machines']);

            return $locked;
        });
    }

    /**
     * Soft-deactivate the target user.
     *
     * Idempotent: a re-DELETE on an already soft-deleted, inactive user
     * returns the existing row (no fields mutated, no tokens to revoke)
     * and writes a `no_op = true` activity row for audit completeness.
     *
     * @throws CannotDeactivateSelfException
     * @throws LastSuperAdminException
     */
    public function deactivate(User $target, User $actor, ?string $ip = null): User
    {
        if ($actor->id === $target->id) {
            throw CannotDeactivateSelfException::create();
        }

        if ($target->trashed() && $target->is_active === false) {
            $this->logActivity(
                event: 'admin.user.deactivated',
                description: 'Admin deactivated user (no-op)',
                target: $target,
                actor: $actor,
                properties: [
                    'ip' => $ip,
                    'tokens_revoked' => 0,
                    'was_already_deleted' => true,
                    'no_op' => true,
                ],
            );
            $target->loadCount(['orders', 'supportTickets', 'machines']);

            return $target;
        }

        return DB::transaction(function () use ($target, $actor, $ip): User {
            /** @var User $locked */
            $locked = User::query()
                ->withTrashed()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->setRelation('roles', $target->roles);

            if ($locked->hasRole(UserRole::SuperAdmin->value)) {
                $remaining = $this->lockAndCountOtherActiveSuperAdmins($locked);
                if ($remaining < 1) {
                    throw LastSuperAdminException::create();
                }
            }

            $locked->forceFill(['is_active' => false])->save();
            $tokensRevoked = $locked->tokens()->delete();
            $locked->delete();

            $this->logActivity(
                event: 'admin.user.deactivated',
                description: 'Admin deactivated user',
                target: $locked,
                actor: $actor,
                properties: [
                    'ip' => $ip,
                    'tokens_revoked' => $tokensRevoked,
                    'was_already_deleted' => false,
                    'no_op' => false,
                ],
            );

            $locked->refresh();
            $locked->load('roles');
            $locked->loadCount(['orders', 'supportTickets', 'machines']);

            return $locked;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) && is_string($filters['search'])
            ? trim($filters['search'])
            : null;
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like, $search): void {
                $q->where('name', 'LIKE', $like)
                    ->orWhere('email', 'LIKE', $like)
                    ->orWhere('phone', 'LIKE', $like)
                    ->orWhereRaw(
                        "CONCAT(COALESCE(phone_country_code, ''), COALESCE(phone, '')) LIKE ?",
                        ['%'.$search.'%'],
                    );
            });
        }

        if (isset($filters['role']) && is_string($filters['role']) && $filters['role'] !== '') {
            $role = $filters['role'];
            $query->whereHas('roles', static function (Builder $rolesQuery) use ($role): void {
                $rolesQuery->where('name', $role);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $query->where('account_type', $filters['type']);
        }

        if (isset($filters['country']) && is_string($filters['country']) && $filters['country'] !== '') {
            $countryCode = strtolower($filters['country']);
            $query->whereHas('addresses', static function (Builder $q) use ($countryCode): void {
                $q->whereHas('country', static function (Builder $countryQuery) use ($countryCode): void {
                    $countryQuery->where('code', $countryCode);
                });
            });
        }

        if (isset($filters['emirate']) && is_string($filters['emirate']) && $filters['emirate'] !== '') {
            $emirate = trim($filters['emirate']);
            $like = '%'.$emirate.'%';
            $query->whereHas('addresses', static function (Builder $q) use ($like, $emirate): void {
                $q->where('city', 'LIKE', $like)
                    ->orWhereHas('region', static function (Builder $r) use ($emirate): void {
                        $r->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.*')) LIKE ?", ['%'.$emirate.'%']);
                    });
            });
        }

        if (isset($filters['created_from']) && is_string($filters['created_from'])) {
            $from = Carbon::parse($filters['created_from'])->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if (isset($filters['created_to']) && is_string($filters['created_to'])) {
            $to = Carbon::parse($filters['created_to'])->endOfDay();
            $query->where('created_at', '<=', $to);
        }
    }

    /**
     * @param  Builder<User>  $query
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

        $query->orderBy($sort, $order)->orderBy('id', $order);
    }

    /**
     * Apply the mass-fillable mutable fields. Returns the list of column
     * names actually changed.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function applyMutableFields(User $user, array $payload): array
    {
        $fillable = [];
        foreach (['name', 'email', 'phone', 'phone_country_code', 'locale', 'company_name'] as $field) {
            if (array_key_exists($field, $payload)) {
                $fillable[$field] = $payload[$field];
            }
        }
        if (array_key_exists('is_active', $payload)) {
            $fillable['is_active'] = (bool) $payload['is_active'];
        }

        if ($fillable === []) {
            return [];
        }

        $user->fill($fillable);
        if (! $user->isDirty()) {
            return [];
        }

        $changedKeys = array_keys($user->getDirty());
        $user->save();

        return $changedKeys;
    }

    /**
     * Locks and counts the OTHER active, non-trashed super_admins.
     *
     * Used to determine whether removing super_admin status (via role
     * change OR deactivation OR is_active toggle) from `$user` would
     * leave the system without one. The lockForUpdate keeps two
     * concurrent demotions/deactivations from racing.
     */
    private function lockAndCountOtherActiveSuperAdmins(User $user): int
    {
        return User::query()
            ->whereHas('roles', static function (Builder $q): void {
                $q->where('name', UserRole::SuperAdmin->value);
            })
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->count();
    }

    /**
     * Pre-transaction guards: caller cannot modify their own role nor
     * deactivate themselves through PATCH. Raises 422 before any DB
     * writes so the audit log doesn't pick up rejected attempts.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CannotChangeOwnRoleException
     * @throws CannotDeactivateSelfException
     */
    private function guardSelfMutations(User $target, User $actor, array $payload): void
    {
        if ($actor->id !== $target->id) {
            return;
        }

        if (array_key_exists('role', $payload)) {
            throw CannotChangeOwnRoleException::create();
        }

        if (array_key_exists('is_active', $payload) && (bool) $payload['is_active'] === false) {
            throw CannotDeactivateSelfException::create();
        }
    }

    /**
     * Inside-transaction guard: would this update remove the last
     * remaining active super_admin?
     *
     * Triggered when:
     *  - `role` is present AND target currently holds super_admin AND
     *    new role !== super_admin.
     *  - `is_active=false` is being applied to a super_admin.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws LastSuperAdminException
     */
    private function guardLastSuperAdmin(User $locked, array $payload): void
    {
        $isSuperAdmin = $locked->hasRole(UserRole::SuperAdmin->value);
        if (! $isSuperAdmin) {
            return;
        }

        $demotingRole = array_key_exists('role', $payload)
            && is_string($payload['role'])
            && $payload['role'] !== UserRole::SuperAdmin->value;

        $deactivating = array_key_exists('is_active', $payload)
            && (bool) $payload['is_active'] === false;

        if (! $demotingRole && ! $deactivating) {
            return;
        }

        $remaining = $this->lockAndCountOtherActiveSuperAdmins($locked);
        if ($remaining < 1) {
            throw LastSuperAdminException::create();
        }
    }

    /**
     * Snapshot the mutable scalar fields for audit-log diffing.
     *
     * @return array<string, mixed>
     */
    private function snapshotFields(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_country_code' => $user->phone_country_code,
            'locale' => $user->locale,
            'is_active' => (bool) $user->is_active,
        ];
    }

    private function resolveRoleName(User $user): ?string
    {
        $adminRole = $this->adminAuthService->resolveAdminRole($user);
        if ($adminRole !== null) {
            return $adminRole;
        }

        $names = $user->getRoleNames()->all();
        if (in_array(UserRole::Customer->value, $names, true)) {
            return UserRole::Customer->value;
        }

        return $names === [] ? null : (string) $names[0];
    }

    /**
     * Write a Spatie ActivityLog row under the `admin.users` log name.
     *
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        string $event,
        string $description,
        User $target,
        User $actor,
        array $properties,
    ): void {
        activity('admin.users')
            ->event($event)
            ->causedBy($actor)
            ->performedOn($target)
            ->withProperties($properties)
            ->log($description);
    }
}
