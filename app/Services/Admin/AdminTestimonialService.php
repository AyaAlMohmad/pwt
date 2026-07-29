<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side testimonial lifecycle service.
 *
 * Owns:
 *  - paginated list with admin filters (incl. inactive + trashed)
 *  - single-testimonial detail (incl. trashed)
 *  - create / PUT update / soft-delete
 *
 * Activity log: `testimonial.created` / `testimonial.updated` /
 * `testimonial.deleted` are emitted automatically by Spatie's LogsActivity
 * trait on {@see Testimonial}. The service writes one consolidated
 * `testimonial.admin_*` row per mutation for Phase 22's audit-log filter.
 *
 * Mutations run inside `DB::transaction(...)` with `lockForUpdate` on the
 * testimonial row.
 *
 * See docs/architecture/admin-content.md § 10.
 */
final class AdminTestimonialService
{
    /**
     * Whitelisted sort columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'display_order' => 'display_order',
        'rating' => 'rating',
        'created_at' => 'created_at',
    ];

    /**
     * Paginated list with admin filters.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Testimonial>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Testimonial> $query */
        $query = $includeTrashed
            ? Testimonial::query()->withTrashed()
            : Testimonial::query();

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Find a testimonial by id including soft-deleted rows.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Testimonial
    {
        /** @var Testimonial $testimonial */
        $testimonial = Testimonial::query()
            ->withTrashed()
            ->findOrFail($id);

        return $testimonial;
    }

    /**
     * Create a testimonial.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Testimonial
    {
        return DB::transaction(function () use ($payload, $actor): Testimonial {
            /** @var Testimonial $testimonial */
            $testimonial = Testimonial::query()->create($this->mutableFields($payload));

            activity('admin.testimonials')
                ->event('testimonial.admin_created')
                ->causedBy($actor)
                ->performedOn($testimonial)
                ->withProperties([
                    'testimonial_id' => (int) $testimonial->id,
                    'name' => $testimonial->name,
                ])
                ->log('Admin created testimonial');

            return $testimonial->refresh();
        });
    }

    /**
     * PUT replace — scalar + translatable fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Testimonial $testimonial, array $payload, User $actor): Testimonial
    {
        return DB::transaction(function () use ($testimonial, $payload, $actor): Testimonial {
            /** @var Testimonial $locked */
            $locked = Testimonial::query()
                ->withTrashed()
                ->whereKey($testimonial->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fields = $this->mutableFields($payload);

            if (array_key_exists('quote', $fields) && is_array($fields['quote'])) {
                $existing = $locked->getTranslations('quote');
                $fields['quote'] = array_merge($existing, $fields['quote']);
            }

            $locked->fill($fields);
            if ($locked->isDirty()) {
                $locked->save();
            }

            activity('admin.testimonials')
                ->event('testimonial.admin_updated')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'testimonial_id' => (int) $locked->id,
                    'changed_fields' => array_keys($locked->getChanges()),
                ])
                ->log('Admin updated testimonial');

            return $locked->refresh();
        });
    }

    /**
     * Soft-delete the testimonial. Idempotent.
     */
    public function delete(Testimonial $testimonial, User $actor): Testimonial
    {
        return DB::transaction(function () use ($testimonial, $actor): Testimonial {
            /** @var Testimonial $locked */
            $locked = Testimonial::query()
                ->withTrashed()
                ->whereKey($testimonial->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->trashed()) {
                $locked->delete();

                activity('admin.testimonials')
                    ->event('testimonial.admin_deleted')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->withProperties([
                        'testimonial_id' => (int) $locked->id,
                        'name' => $locked->name,
                    ])
                    ->log('Admin deleted testimonial');
            }

            return $locked->refresh();
        });
    }

    /**
     * @param  Builder<Testimonial>  $query
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
                $q->where('name', 'LIKE', $like)
                    ->orWhere('company', 'LIKE', $like)
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(quote, '$.en')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(quote, '$.ar')) LIKE ?", [$like]);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['rating']) && is_numeric($filters['rating'])) {
            $query->where('rating', (int) $filters['rating']);
        }
        if (isset($filters['rating_min']) && is_numeric($filters['rating_min'])) {
            $query->where('rating', '>=', (int) $filters['rating_min']);
        }
        if (isset($filters['rating_max']) && is_numeric($filters['rating_max'])) {
            $query->where('rating', '<=', (int) $filters['rating_max']);
        }
    }

    /**
     * @param  Builder<Testimonial>  $query
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
            'name', 'role', 'company', 'avatar_url', 'rating', 'quote',
            'display_order', 'is_active',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        return $out;
    }
}
