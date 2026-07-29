<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side banner lifecycle service.
 *
 * Owns:
 *  - paginated list with admin filters (incl. inactive + trashed)
 *  - single-banner detail (incl. trashed)
 *  - create / PUT update / soft-delete
 *
 * Activity log: `banner.created` / `banner.updated` / `banner.deleted` are
 * emitted automatically by the Spatie LogsActivity trait on {@see Banner}
 * — the service writes one consolidated `banner.admin_*` row per mutation
 * with the actor causer so the Phase 22 audit-log filter can isolate
 * admin-driven mutations from automated scripts.
 *
 * Mutations run inside `DB::transaction(...)` with `lockForUpdate` on the
 * banner row to defeat concurrent writes.
 *
 * See docs/architecture/admin-content.md § 10.
 */
final class AdminBannerService
{
    /**
     * Whitelisted sort columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'display_order' => 'display_order',
        'starts_at' => 'starts_at',
        'ends_at' => 'ends_at',
        'created_at' => 'created_at',
    ];

    /**
     * Paginated list with admin filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Banner>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Banner> $query */
        $query = $includeTrashed
            ? Banner::query()->withTrashed()
            : Banner::query();

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Find a banner by id including soft-deleted rows.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Banner
    {
        /** @var Banner $banner */
        $banner = Banner::query()
            ->withTrashed()
            ->findOrFail($id);

        return $banner;
    }

    /**
     * Create a banner.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Banner
    {
        return DB::transaction(function () use ($payload, $actor): Banner {
            /** @var Banner $banner */
            $banner = Banner::query()->create($this->mutableFields($payload));

            activity('admin.banners')
                ->event('banner.admin_created')
                ->causedBy($actor)
                ->performedOn($banner)
                ->withProperties([
                    'banner_id' => (int) $banner->id,
                    'code' => $banner->code,
                ])
                ->log('Admin created banner');

            return $banner->refresh();
        });
    }

    /**
     * PUT replace — scalar + translatable fields.
     *
     * Bilingual fields apply fallback-merge: if only `name.en` is provided
     * on PUT and the stored row has both `en` and `ar`, the existing `ar`
     * is retained (per docs/architecture/admin-content.md § 3.4).
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Banner $banner, array $payload, User $actor): Banner
    {
        return DB::transaction(function () use ($banner, $payload, $actor): Banner {
            /** @var Banner $locked */
            $locked = Banner::query()
                ->withTrashed()
                ->whereKey($banner->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fields = $this->mutableFields($payload);
            // `code` is immutable post-creation — defensive drop in case
            // a caller bypassed the Form Request.
            unset($fields['code']);

            // Merge translatable fields with stored translations so a
            // partial PUT does not blow away the other locale.
            foreach (['title', 'subtitle', 'cta_label'] as $key) {
                if (array_key_exists($key, $fields) && is_array($fields[$key])) {
                    $existing = $locked->getTranslations($key);
                    $fields[$key] = array_merge($existing, $fields[$key]);
                }
            }

            $locked->fill($fields);
            if ($locked->isDirty()) {
                $locked->save();
            }

            activity('admin.banners')
                ->event('banner.admin_updated')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'banner_id' => (int) $locked->id,
                    'changed_fields' => array_keys($locked->getChanges()),
                ])
                ->log('Admin updated banner');

            return $locked->refresh();
        });
    }

    /**
     * Soft-delete the banner. Idempotent — re-deleting a trashed banner
     * is a no-op.
     */
    public function delete(Banner $banner, User $actor): Banner
    {
        return DB::transaction(function () use ($banner, $actor): Banner {
            /** @var Banner $locked */
            $locked = Banner::query()
                ->withTrashed()
                ->whereKey($banner->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->trashed()) {
                $locked->delete();

                activity('admin.banners')
                    ->event('banner.admin_deleted')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->withProperties([
                        'banner_id' => (int) $locked->id,
                        'code' => $locked->code,
                    ])
                    ->log('Admin deleted banner');
            }

            return $locked->refresh();
        });
    }

    /**
     * @param  Builder<Banner>  $query
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
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(title, '$.en')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(title, '$.ar')) LIKE ?", [$like]);
            });
        }

        if (isset($filters['placement']) && is_string($filters['placement']) && $filters['placement'] !== '') {
            $query->where('placement', $filters['placement']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
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
     * @param  Builder<Banner>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'display_order';
        $column = self::SORT_COLUMNS[$sortInput] ?? 'display_order';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'asc';
        $order = $order === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $order)->orderBy('id', 'asc');
    }

    /**
     * Reduce the incoming payload to the model's $fillable subset.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mutableFields(array $payload): array
    {
        $out = [];
        foreach ([
            'code', 'placement', 'title', 'subtitle', 'image_url',
            'image_url_ar', 'cta_label', 'cta_url', 'starts_at', 'ends_at',
            'display_order', 'is_active',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        // Normalise placement to the enum value (it may come in as the
        // raw string from the request).
        if (isset($out['placement']) && is_string($out['placement'])) {
            $enum = BannerPlacement::tryFrom($out['placement']);
            if ($enum !== null) {
                $out['placement'] = $enum->value;
            }
        }

        return $out;
    }
}
