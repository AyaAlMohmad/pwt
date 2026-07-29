<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\Admin\Catalog\CategoryHasProductsException;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side categories lifecycle service.
 *
 * Carries the full CRUD for the admin /categories endpoints. All
 * mutations run inside `DB::transaction(...)` so an interleaved DELETE
 * cannot race past the "category has products" guard.
 *
 * Activity log writes are mostly emitted automatically by Spatie's
 * `LogsActivity` trait on {@see Category}; this service does not
 * manually fire create/update/delete events.
 *
 * See docs/architecture/admin-catalog.md § 10.1.
 */
final class AdminCategoryService
{
    /**
     * Whitelisted sort columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'display_order' => 'display_order',
        'code' => 'code',
        'created_at' => 'created_at',
        // `name` is handled separately as a JSON-extract clause.
    ];

    /**
     * Paginated list with admin filters (includes inactive + trashed
     * when `include_trashed` is true).
     *
     * @param  array{
     *   search?: string|null,
     *   is_active?: bool|null,
     *   parent_id?: int|null,
     *   include_trashed?: bool|null,
     *   sort?: string|null,
     *   order?: string|null,
     * }  $filters
     * @return LengthAwarePaginator<int, Category>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Category> $query */
        $query = $includeTrashed
            ? Category::query()->withTrashed()
            : Category::query();

        $query->withCount('products');

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Find a category by id including soft-deleted rows so the dashboard
     * can render a "deactivated" detail view.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Category
    {
        /** @var Category $category */
        $category = Category::query()
            ->withTrashed()
            ->with('parent')
            ->withCount([
                'products',
                'products as active_products_count' => static function (Builder $q): void {
                    $q->where('is_active', true)->whereNull('deleted_at');
                },
            ])
            ->findOrFail($id);

        return $category;
    }

    /**
     * Create a category. The Spatie HasTranslations trait stores the
     * bilingual `name` / `description` JSON when supplied as arrays.
     *
     * Activity log entry `category.created` is written automatically by
     * the model's `LogsActivity` trait.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Category
    {
        return DB::transaction(function () use ($payload): Category {
            /** @var Category $category */
            $category = Category::query()->create($this->mutableFields($payload));

            return $this->refresh($category);
        });
    }

    /**
     * Apply a PUT update. The model's `LogsActivity` trait emits
     * `category.updated` automatically with the dirty diff.
     *
     * @param  array<string, mixed>  $payload
     */
    public function update(Category $category, array $payload, User $actor): Category
    {
        return DB::transaction(function () use ($category, $payload): Category {
            /** @var Category $locked */
            $locked = Category::query()
                ->withTrashed()
                ->whereKey($category->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill($this->mutableFields($payload));
            $locked->save();

            return $this->refresh($locked);
        });
    }

    /**
     * Soft-delete the category after the active-product guard.
     *
     * @throws CategoryHasProductsException
     */
    public function deactivate(Category $category, User $actor): Category
    {
        return DB::transaction(function () use ($category): Category {
            /** @var Category $locked */
            $locked = Category::query()
                ->withTrashed()
                ->whereKey($category->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Already trashed: no-op idempotent return.
            if ($locked->trashed()) {
                return $this->refresh($locked);
            }

            $count = Product::query()
                ->where('category_id', $locked->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->count();

            if ($count > 0) {
                throw CategoryHasProductsException::create($count);
            }

            // Soft-delete — model trait writes `category.deleted` activity.
            $locked->delete();

            return $this->refresh($locked);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Category>  $query
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

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['parent_id']) && is_numeric($filters['parent_id'])) {
            $query->where('parent_id', (int) $filters['parent_id']);
        }
    }

    /**
     * @param  Builder<Category>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'display_order';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : ($sortInput === 'created_at' ? 'desc' : 'asc');
        $order = $order === 'desc' ? 'desc' : 'asc';

        if ($sortInput === 'name') {
            $query->orderByRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) ".strtoupper($order),
            );
        } else {
            $column = self::SORT_COLUMNS[$sortInput] ?? 'display_order';
            $query->orderBy($column, $order);
        }

        $query->orderBy('id', 'asc');
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
        foreach (['parent_id', 'code', 'name', 'description', 'image_url', 'display_order', 'is_active'] as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        return $out;
    }

    private function refresh(Category $category): Category
    {
        $category->refresh();
        $category->load('parent');
        $category->loadCount([
            'products',
            'products as active_products_count' => static function (Builder $q): void {
                $q->where('is_active', true)->whereNull('deleted_at');
            },
        ]);

        return $category;
    }
}
