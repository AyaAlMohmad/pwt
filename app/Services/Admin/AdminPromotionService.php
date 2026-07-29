<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\DiscountType;
use App\Enums\PromoScope;
use App\Exceptions\Admin\Promotions\PromotionHasRedemptionsException;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Promotions\PromotionEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side promotion lifecycle service.
 *
 * Owns:
 *  - paginated list with admin filters (incl. inactive + scheduled +
 *    expired + trashed) and `redemptions_count` via `withCount`.
 *  - single-promotion detail (incl. trashed) with full applies_to scope
 *    eager-loaded.
 *  - create with `applies_to=products|categories` pivot inserts inside
 *    the transaction.
 *  - PUT update with replace-all pivot semantics. `code` is immutable
 *    post-creation.
 *  - DELETE blocked by {@see PromotionHasRedemptionsException} when any
 *    `promotion_redemptions` row references the promotion (audit
 *    invariant — docs/architecture/admin-content.md § 5.5 / § 9.1).
 *
 * Activity log: `promotion.created` / `promotion.updated` /
 * `promotion.deleted` are emitted automatically by Spatie's LogsActivity
 * trait on {@see Promotion}. The service adds one consolidated
 * `promotion.admin_*` row per mutation so Phase 22's audit-log filter
 * can isolate admin-driven changes.
 *
 * Mutations run inside `DB::transaction(...)` with `lockForUpdate` on the
 * promotion row.
 *
 * See docs/architecture/admin-content.md § 10.
 */
final class AdminPromotionService
{
    /**
     * Whitelisted sort columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'code' => 'code',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'created_at' => 'created_at',
    ];

    /**
     * Paginated list with admin filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Promotion>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Promotion> $query */
        $query = $includeTrashed
            ? Promotion::query()->withTrashed()
            : Promotion::query();

        $query
            ->withCount('redemptions')
            ->with(['products:id,code,name']);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Find a promotion by id including soft-deleted rows. Eager-loads the
     * applicable scope (`products` OR `categories`) and the redemption
     * aggregates used by the detail resource.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Promotion
    {
        /** @var Promotion $promotion */
        $promotion = Promotion::query()
            ->withTrashed()
            ->withCount('redemptions')
            ->with(['products', 'categories'])
            ->findOrFail($id);

        // Aggregate computations exposed on detail resource.
        $promotion->setAttribute(
            'total_amount_redeemed',
            (string) ($promotion->redemptions()->sum('amount_applied') ?: '0.00'),
        );
        $promotion->setAttribute(
            'unique_users_count',
            $promotion->redemptions()->distinct('user_id')->count('user_id'),
        );

        return $promotion;
    }

    /**
     * Create a promotion. `product_ids` / `category_ids` arrays — when
     * present — are inserted as pivot rows inside the same transaction.
     *
     * `code` is uppercased + trimmed server-side to match the
     * {@see PromotionEngine} lookup contract.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Promotion
    {
        return DB::transaction(function () use ($payload, $actor): Promotion {
            $fields = $this->mutableFields($payload);
            $fields['code'] = strtoupper(trim((string) $fields['code']));

            /** @var Promotion $promotion */
            $promotion = Promotion::query()->create($fields);

            $this->syncScopePivots($promotion, $payload);

            activity('admin.promotions')
                ->event('promotion.admin_created')
                ->causedBy($actor)
                ->performedOn($promotion)
                ->withProperties([
                    'promotion_id' => (int) $promotion->id,
                    'code' => $promotion->code,
                    'applies_to' => $promotion->applies_to->value,
                ])
                ->log('Admin created promotion');

            return $this->find((int) $promotion->id);
        });
    }

    /**
     * PUT replace — scalar + translatable fields + pivot replace-all.
     *
     * `code` is immutable post-creation; the Form Request already drops
     * `code` via prepareForValidation, but the service strips defensively.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Promotion $promotion, array $payload, User $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $payload, $actor): Promotion {
            /** @var Promotion $locked */
            $locked = Promotion::query()
                ->withTrashed()
                ->whereKey($promotion->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fields = $this->mutableFields($payload);
            unset($fields['code']); // immutable

            foreach (['name', 'description'] as $key) {
                if (array_key_exists($key, $fields) && is_array($fields[$key])) {
                    $existing = $locked->getTranslations($key);
                    $fields[$key] = array_merge($existing, $fields[$key]);
                }
            }

            $locked->fill($fields);
            if ($locked->isDirty()) {
                $locked->save();
            }

            // Pivot replace-all when `applies_to` is sent OR when pivot
            // arrays are explicitly present in the payload.
            $this->syncScopePivots($locked, $payload);

            activity('admin.promotions')
                ->event('promotion.admin_updated')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'promotion_id' => (int) $locked->id,
                    'changed_fields' => array_keys($locked->getChanges()),
                ])
                ->log('Admin updated promotion');

            return $this->find((int) $locked->id);
        });
    }

    /**
     * Soft-delete blocked by the redemptions audit invariant.
     *
     * @throws PromotionHasRedemptionsException
     */
    public function delete(Promotion $promotion, User $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $actor): Promotion {
            /** @var Promotion $locked */
            $locked = Promotion::query()
                ->withTrashed()
                ->whereKey($promotion->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->trashed()) {
                return $this->find((int) $locked->id);
            }

            $count = $locked->redemptions()->count();
            if ($count > 0) {
                throw PromotionHasRedemptionsException::create($count);
            }

            $locked->delete();

            activity('admin.promotions')
                ->event('promotion.admin_deleted')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'promotion_id' => (int) $locked->id,
                    'code' => $locked->code,
                ])
                ->log('Admin deleted promotion');

            return $this->find((int) $locked->id);
        });
    }

    /**
     * Replace-all the applies_to pivot rows. Called on create AND update.
     *
     * - applies_to=all       → purge both pivot tables.
     * - applies_to=products  → sync `product_ids`.
     * - applies_to=categories → sync `category_ids`.
     *
     * No-op when `applies_to` was not touched AND no pivot arrays were
     * sent in the payload (PUT semantics: omitted means unchanged).
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncScopePivots(Promotion $promotion, array $payload): void
    {
        $appliesTo = $promotion->applies_to;
        $hasProductIds = array_key_exists('product_ids', $payload) && is_array($payload['product_ids']);
        $hasCategoryIds = array_key_exists('category_ids', $payload) && is_array($payload['category_ids']);
        $hasAppliesTo = array_key_exists('applies_to', $payload);

        if (! $hasAppliesTo && ! $hasProductIds && ! $hasCategoryIds) {
            return;
        }

        if ($appliesTo === PromoScope::All) {
            $promotion->products()->sync([]);
            $promotion->categories()->sync([]);

            return;
        }

        if ($appliesTo === PromoScope::Products) {
            $ids = $hasProductIds
                ? array_values(array_unique(array_map('intval', $payload['product_ids'])))
                : [];
            $promotion->products()->sync($ids);
            $promotion->categories()->sync([]);

            return;
        }

        // Categories.
        $ids = $hasCategoryIds
            ? array_values(array_unique(array_map('intval', $payload['category_ids'])))
            : [];
        $promotion->categories()->sync($ids);
        $promotion->products()->sync([]);
    }

    /**
     * @param  Builder<Promotion>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) && is_string($filters['search'])
            ? trim($filters['search'])
            : null;
        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('code', 'LIKE', $like)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", [$like]);
            });
        }

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['applies_to']) && is_string($filters['applies_to']) && $filters['applies_to'] !== '') {
            $query->where('applies_to', $filters['applies_to']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $this->applyStatusFilter($query, $filters['status']);
        }

        foreach ([
            'starts_before' => ['starts_at', '<='],
            'starts_after' => ['starts_at', '>='],
            'ends_before' => ['ends_at', '<='],
            'ends_after' => ['ends_at', '>='],
        ] as $key => [$column, $op]) {
            if (isset($filters[$key]) && is_string($filters[$key])) {
                $query->where($column, $op, Carbon::parse($filters[$key]));
            }
        }
    }

    /**
     * Synthetic `status` filter computed from is_active + window vs now.
     *
     * @param  Builder<Promotion>  $query
     */
    private function applyStatusFilter(Builder $query, string $status): void
    {
        $now = now();
        match ($status) {
            'inactive' => $query->where('is_active', false),
            'upcoming' => $query
                ->where('is_active', true)
                ->where('starts_at', '>', $now),
            'expired' => $query
                ->where('is_active', true)
                ->where('ends_at', '<', $now),
            'active' => $query
                ->where('is_active', true)
                ->where(static function (Builder $q) use ($now): void {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(static function (Builder $q) use ($now): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                }),
            default => null,
        };
    }

    /**
     * @param  Builder<Promotion>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'starts_at';
        $column = self::SORT_COLUMNS[$sortInput] ?? 'starts_at';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'desc';
        $order = $order === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $order)->orderBy('id', 'desc');
    }

    /**
     * Reduce the incoming payload to the model's $fillable subset and
     * normalise enum / currency strings.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mutableFields(array $payload): array
    {
        $out = [];
        foreach ([
            'code', 'name', 'description', 'type', 'value',
            'max_discount_amount', 'currency', 'min_order_amount',
            'starts_at', 'ends_at', 'per_user_limit', 'total_limit',
            'applies_to', 'is_active',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        if (isset($out['currency']) && is_string($out['currency'])) {
            $out['currency'] = strtoupper($out['currency']);
        }

        if (isset($out['type']) && is_string($out['type'])) {
            $enum = DiscountType::tryFrom($out['type']);
            if ($enum !== null) {
                $out['type'] = $enum->value;
            }
        }
        if (isset($out['applies_to']) && is_string($out['applies_to'])) {
            $enum = PromoScope::tryFrom($out['applies_to']);
            if ($enum !== null) {
                $out['applies_to'] = $enum->value;
            }
        }

        return $out;
    }
}
