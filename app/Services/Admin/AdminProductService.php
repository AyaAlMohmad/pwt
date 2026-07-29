<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\StockStatus;
use App\Exceptions\Admin\Catalog\ProductCodeImmutableException;
use App\Exceptions\Admin\Catalog\ProductHasActiveOrdersException;
use App\Models\File;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSpec;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side products lifecycle service.
 *
 * Owns:
 *  - paginated list with admin filters (incl. inactive + trashed)
 *  - single-product detail (incl. trashed)
 *  - create with optional inline `prices` + `specs`
 *  - PUT update with replace-all semantics on prices + specs
 *  - PATCH status toggle (4 flags)
 *  - DELETE with active-orders guard
 *  - attach / detach image rows
 *
 * Activity log:
 *  - `product.created` / `product.updated` / `product.deleted` are
 *    emitted automatically by Spatie's LogsActivity trait on
 *    {@see Product}.
 *  - Price + spec replace operations write ONE consolidated activity
 *    row each (`product.prices_replaced`, `product.specs_replaced`)
 *    with before/after snapshots.
 *  - Image attach / detach emit `product.image_attached` /
 *    `product.image_detached` events.
 *  - Status toggle additionally writes `product.status_changed` so
 *    Phase 22's audit-log filter can isolate these.
 *
 * Mutations run inside `DB::transaction(...)` with `lockForUpdate` on
 * the product row whenever child rows (prices / specs / images) are
 * rewritten or guard counts are read.
 *
 * See docs/architecture/admin-catalog.md § 10.2.
 */
final class AdminProductService
{
    /**
     * Whitelisted sort columns.
     *
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at',
        'code' => 'code',
        'display_order' => 'display_order',
        // `name` handled via JSON-extract clause.
    ];

    /**
     * Non-terminal order statuses for the DELETE-blocking guard.
     *
     * Aligned with docs/architecture/admin-catalog.md § 5.3 — `installed`
     * is intentionally OMITTED here because the orchestrator decision
     * scopes the guard to {pending, confirmed, scheduled, out_for_delivery}.
     *
     * @var list<string>
     */
    private const ACTIVE_ORDER_STATUSES = [
        'pending',
        'confirmed',
        'scheduled',
        'out_for_delivery',
    ];

    /**
     * Paginated list with admin filters (incl. inactive + trashed).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $includeTrashed = (bool) ($filters['include_trashed'] ?? false);

        /** @var Builder<Product> $query */
        $query = $includeTrashed
            ? Product::query()->withTrashed()
            : Product::query();

        $query
            ->with([
                'category',
                'prices',
                'specs',
                'images' => function ($q): void {
                    $q->orderBy('display_order')->limit(1);
                },
                'images.file',
            ])
            ->withCount([
                'images',
                'orderItems as orders_count' => function (Builder $q): void {
                    $q->whereHas('order', function (Builder $orderQuery): void {
                        $orderQuery->whereIn('status', self::ACTIVE_ORDER_STATUSES)
                            ->whereNull('deleted_at');
                    });
                },
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Find a product by id including soft-deleted rows.
     *
     * @throws ModelNotFoundException
     */
    public function find(int $id): Product
    {
        /** @var Product $product */
        $product = Product::query()
            ->withTrashed()
            ->with(['category', 'images', 'specs', 'prices'])
            ->withCount([
                'images',
                'orderItems as orders_count' => function (Builder $q): void {
                    $q->whereHas('order', function (Builder $orderQuery): void {
                        $orderQuery->whereIn('status', self::ACTIVE_ORDER_STATUSES)
                            ->whereNull('deleted_at');
                    });
                },
            ])
            ->findOrFail($id);

        return $product;
    }

    /**
     * Create a product, optionally with inline prices/specs.
     *
     * Activity rows:
     *  - `product.created` from the model trait (automatic).
     *  - `product.prices_replaced` (manual) when prices submitted.
     *  - `product.specs_replaced` (manual) when specs submitted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): Product
    {
        return DB::transaction(function () use ($payload, $actor): Product {
            /** @var Product $product */
            $product = Product::query()->create($this->mutableFields($payload));

            if (array_key_exists('prices', $payload) && is_array($payload['prices'])) {
                $this->replacePrices($product, $payload['prices'], $actor);
            }

            if (array_key_exists('specs', $payload) && is_array($payload['specs'])) {
                $this->replaceSpecs($product, $payload['specs'], $actor);
            }

            return $this->refresh($product);
        });
    }

    /**
     * PUT replace — scalar fields plus optional replace-all on prices /
     * specs. `code` is immutable post-creation; the Form Request already
     * rejects `code`, but the service guards defensively.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ProductCodeImmutableException
     */
    public function update(Product $product, array $payload, User $actor): Product
    {
        if (array_key_exists('code', $payload)) {
            throw ProductCodeImmutableException::create();
        }

        return DB::transaction(function () use ($product, $payload, $actor): Product {
            /** @var Product $locked */
            $locked = Product::query()
                ->withTrashed()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->fill($this->mutableFields($payload));
            if ($locked->isDirty()) {
                $locked->save();
            }

            if (array_key_exists('prices', $payload) && is_array($payload['prices'])) {
                $this->replacePrices($locked, $payload['prices'], $actor);
            }

            if (array_key_exists('specs', $payload) && is_array($payload['specs'])) {
                $this->replaceSpecs($locked, $payload['specs'], $actor);
            }

            return $this->refresh($locked);
        });
    }

    /**
     * Toggle the four status flags. Writes a specialised
     * `product.status_changed` activity row in addition to the
     * automatic `product.updated` event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateStatus(Product $product, array $payload, User $actor): Product
    {
        return DB::transaction(function () use ($product, $payload, $actor): Product {
            /** @var Product $locked */
            $locked = Product::query()
                ->withTrashed()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'is_active' => (bool) $locked->is_active,
                'is_featured' => (bool) $locked->is_featured,
                'is_quote_only' => (bool) $locked->is_quote_only,
                'individual_buy_enabled' => (bool) $locked->individual_buy_enabled,
                'stock_status' => $locked->stock_status->value,
            ];

            $fillable = [];
            foreach (['is_active', 'is_featured', 'is_quote_only', 'individual_buy_enabled'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $fillable[$key] = (bool) $payload[$key];
                }
            }
            if (array_key_exists('stock_status', $payload)) {
                $fillable['stock_status'] = $payload['stock_status'];
            }

            $locked->fill($fillable);
            if ($locked->isDirty()) {
                $locked->save();
            }

            $after = [
                'is_active' => (bool) $locked->is_active,
                'is_featured' => (bool) $locked->is_featured,
                'is_quote_only' => (bool) $locked->is_quote_only,
                'individual_buy_enabled' => (bool) $locked->individual_buy_enabled,
                'stock_status' => $locked->stock_status->value,
            ];

            $changed = array_keys(array_filter(
                $after,
                static fn (mixed $v, string $k): bool => $before[$k] !== $v,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($changed !== []) {
                activity('product')
                    ->event('product.status_changed')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->withProperties([
                        'before' => $before,
                        'after' => $after,
                        'changed_fields' => $changed,
                    ])
                    ->log('Admin changed product status');
            }

            return $this->refresh($locked);
        });
    }

    /**
     * Soft-delete after the active-orders guard.
     *
     * @throws ProductHasActiveOrdersException
     */
    public function deactivate(Product $product, User $actor): Product
    {
        return DB::transaction(function () use ($product): Product {
            /** @var Product $locked */
            $locked = Product::query()
                ->withTrashed()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->trashed()) {
                return $this->refresh($locked);
            }

            $count = OrderItem::query()
                ->where('product_id', $locked->id)
                ->whereHas('order', function (Builder $q): void {
                    $q->whereIn('status', self::ACTIVE_ORDER_STATUSES)
                        ->whereNull('deleted_at');
                })
                ->lockForUpdate()
                ->count();

            if ($count > 0) {
                throw ProductHasActiveOrdersException::create($count);
            }

            $locked->forceFill(['is_active' => false])->save();
            $locked->delete();

            return $this->refresh($locked);
        });
    }

    /**
     * Attach an existing files row as a product image.
     *
     * Transactional — when `is_primary=true`, demotes the previous
     * primary atomically. The pair (`product_id`, `file_id`) is unique:
     * re-attaching the same file silently succeeds by updating any
     * existing row (idempotent — admin clicks "attach" twice).
     *
     * @param  array<string, mixed>  $payload
     */
    public function attachImage(Product $product, array $payload, User $actor): Product
    {
        /** @var list<int> $fileIds */
        $fileIds = array_map('intval', $payload['file_ids'] ?? []);

        return DB::transaction(function () use ($product, $payload, $actor, $fileIds): Product {
            /** @var Product $locked */
            $locked = Product::query()
                ->withTrashed()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $isPrimary = (bool) ($payload['is_primary'] ?? false);
            $altText = $payload['alt_text'] ?? null;
            $displayOrder = $payload['display_order'] ?? null;

            $demotedPreviousPrimaryId = null;
            if ($isPrimary) {
                $existingPrimary = ProductImage::query()
                    ->where('product_id', $locked->id)
                    ->where('is_primary', true)
                    ->whereNotIn('file_id', $fileIds)
                    ->lockForUpdate()
                    ->first();
                if ($existingPrimary !== null) {
                    $demotedPreviousPrimaryId = (int) $existingPrimary->id;
                    ProductImage::query()
                        ->where('product_id', $locked->id)
                        ->where('is_primary', true)
                        ->update(['is_primary' => false]);
                }
            }

            $attachedImageIds = [];
            $maxOrder = (int) (ProductImage::query()
                ->where('product_id', $locked->id)
                ->max('display_order') ?? -1);

            foreach ($fileIds as $index => $fileId) {
                /** @var File|null $file */
                $file = File::query()->find($fileId);
                if ($file === null) {
                    continue;
                }

                $itemIsPrimary = ($index === 0) ? $isPrimary : false;
                $itemDisplayOrder = $displayOrder !== null
                    ? ($displayOrder + $index)
                    : ($maxOrder + $index + 1);

                /** @var ProductImage|null $existing */
                $existing = ProductImage::query()
                    ->where('product_id', $locked->id)
                    ->where('file_id', $file->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->fill([
                        'url' => $file->path,
                        'alt_text' => $altText,
                        'is_primary' => $itemIsPrimary,
                        'display_order' => $itemDisplayOrder,
                    ])->save();
                    $image = $existing;
                } else {
                    /** @var ProductImage $image */
                    $image = ProductImage::query()->create([
                        'product_id' => $locked->id,
                        'file_id' => $file->id,
                        'url' => $file->path,
                        'alt_text' => $altText,
                        'is_primary' => $itemIsPrimary,
                        'display_order' => $itemDisplayOrder,
                    ]);
                }

                $attachedImageIds[] = (int) $image->id;
            }

            activity('product.images')
                ->event('product.image_attached')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'image_ids' => $attachedImageIds,
                    'file_ids' => $fileIds,
                    'is_primary' => $isPrimary,
                    'demoted_previous_primary' => $demotedPreviousPrimaryId,
                ])
                ->log('Admin attached product images');

            return $this->refresh($locked);
        });
    }

    /**
     * Detach (hard-delete) a single image row.
     *
     * The controller is responsible for the cross-product mismatch check
     * (returns 404 in that case). The service assumes a valid pair.
     */
    public function detachImage(Product $product, ProductImage $image, User $actor): Product
    {
        return DB::transaction(function () use ($product, $image, $actor): Product {
            /** @var Product $locked */
            $locked = Product::query()
                ->withTrashed()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payload = [
                'image_id' => (int) $image->id,
                'file_id' => $image->file_id !== null ? (int) $image->file_id : null,
                'was_primary' => (bool) $image->is_primary,
            ];

            $image->delete();

            activity('product.images')
                ->event('product.image_detached')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties($payload)
                ->log('Admin detached product image');

            return $this->refresh($locked);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Price + Spec replace-all helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Replace-all the product's price rows in one atomic operation.
     *
     * Writes one consolidated `product.prices_replaced` activity row with
     * before/after snapshots so the audit log gets a clean diff (per
     * orchestrator's "Activity log every price change with before/after").
     *
     * @param  array<int, array<string, mixed>>  $prices
     */
    private function replacePrices(Product $product, array $prices, User $actor): void
    {
        // Snapshot BEFORE — full row arrays.
        $before = ProductPrice::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->get()
            ->map(fn (ProductPrice $p): array => [
                'id' => (int) $p->id,
                'term' => $p->term->value,
                'amount' => (string) $p->amount,
                'currency' => $p->currency,
                'period' => $p->period,
                'min_term_months' => $p->min_term_months !== null ? (int) $p->min_term_months : null,
                'is_active' => (bool) $p->is_active,
            ])
            ->all();

        ProductPrice::query()->where('product_id', $product->id)->delete();

        $now = Carbon::now();
        $inserts = [];
        foreach ($prices as $row) {
            $inserts[] = [
                'product_id' => $product->id,
                'term' => (string) $row['term'],
                'amount' => (string) $row['amount'],
                'currency' => strtoupper((string) $row['currency']),
                'period' => isset($row['period']) && $row['period'] !== '' ? (string) $row['period'] : null,
                'min_term_months' => isset($row['min_term_months']) && $row['min_term_months'] !== ''
                    ? (int) $row['min_term_months']
                    : null,
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($inserts !== []) {
            ProductPrice::query()->insert($inserts);
        }

        // Snapshot AFTER.
        $after = ProductPrice::query()
            ->where('product_id', $product->id)
            ->get()
            ->map(fn (ProductPrice $p): array => [
                'id' => (int) $p->id,
                'term' => $p->term->value,
                'amount' => (string) $p->amount,
                'currency' => $p->currency,
                'period' => $p->period,
                'min_term_months' => $p->min_term_months !== null ? (int) $p->min_term_months : null,
                'is_active' => (bool) $p->is_active,
            ])
            ->all();

        activity('product.prices')
            ->event('product.prices_replaced')
            ->causedBy($actor)
            ->performedOn($product)
            ->withProperties([
                'before' => $before,
                'after' => $after,
                'before_count' => count($before),
                'after_count' => count($after),
            ])
            ->log('Admin replaced product prices');

        // Warning event when discontinued + active prices remain — per
        // docs/architecture/admin-catalog.md § 3.5.2.
        $activeCount = 0;
        foreach ($after as $row) {
            if ($row['is_active'] === true) {
                $activeCount++;
            }
        }
        if ($product->stock_status === StockStatus::Discontinued && $activeCount > 0) {
            activity('product')
                ->event('product.discontinued_with_active_prices')
                ->causedBy($actor)
                ->performedOn($product)
                ->withProperties([
                    'active_price_count' => $activeCount,
                ])
                ->log('Discontinued product still has active prices');
        }
    }

    /**
     * Replace-all the product's spec rows. One `product.specs_replaced`
     * activity row carries the before/after snapshot.
     *
     * @param  array<int, array<string, mixed>>  $specs
     */
    private function replaceSpecs(Product $product, array $specs, User $actor): void
    {
        $before = ProductSpec::query()
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->get()
            ->map(fn (ProductSpec $s): array => [
                'id' => (int) $s->id,
                'label' => $s->getTranslations('label'),
                'value' => $s->getTranslations('value'),
                'display_order' => (int) $s->display_order,
            ])
            ->all();

        ProductSpec::query()->where('product_id', $product->id)->delete();

        foreach ($specs as $index => $row) {
            ProductSpec::query()->create([
                'product_id' => $product->id,
                'label' => $row['label'],
                'value' => $row['value'],
                'display_order' => isset($row['display_order']) ? (int) $row['display_order'] : $index,
            ]);
        }

        $after = ProductSpec::query()
            ->where('product_id', $product->id)
            ->get()
            ->map(fn (ProductSpec $s): array => [
                'id' => (int) $s->id,
                'label' => $s->getTranslations('label'),
                'value' => $s->getTranslations('value'),
                'display_order' => (int) $s->display_order,
            ])
            ->all();

        activity('product.specs')
            ->event('product.specs_replaced')
            ->causedBy($actor)
            ->performedOn($product)
            ->withProperties([
                'before' => $before,
                'after' => $after,
                'before_count' => count($before),
                'after_count' => count($after),
            ])
            ->log('Admin replaced product specs');
    }

    /*
    |--------------------------------------------------------------------------
    | Filter / sort / refresh helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Product>  $query
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
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(name, '$.ar')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(short_description, '$.en')) LIKE ?", [$like])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(short_description, '$.ar')) LIKE ?", [$like]);
            });
        }

        if (isset($filters['category_id']) && is_numeric($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        foreach (['is_active', 'is_featured', 'is_quote_only'] as $flag) {
            if (array_key_exists($flag, $filters) && $filters[$flag] !== null) {
                $query->where($flag, (bool) $filters[$flag]);
            }
        }

        if (isset($filters['stock_status']) && is_string($filters['stock_status'])) {
            $query->where('stock_status', $filters['stock_status']);
        }

        if (isset($filters['term']) && is_string($filters['term'])) {
            $term = $filters['term'];
            $query->whereHas('prices', static function (Builder $q) use ($term): void {
                $q->where('term', $term);
            });
        }

        if (isset($filters['created_from']) && is_string($filters['created_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['created_from'])->startOfDay());
        }

        if (isset($filters['created_to']) && is_string($filters['created_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['created_to'])->endOfDay());
        }
    }

    /**
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sortInput = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'created_at';

        $order = isset($filters['order']) && is_string($filters['order'])
            ? strtolower($filters['order'])
            : 'desc';
        $order = $order === 'asc' ? 'asc' : 'desc';

        if ($sortInput === 'name') {
            $query->orderByRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(name, '$.en')) ".strtoupper($order),
            );
        } else {
            $column = self::SORT_COLUMNS[$sortInput] ?? 'created_at';
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
        foreach ([
            'category_id', 'code', 'name', 'short_description', 'description',
            'benefits', 'is_active', 'is_featured', 'is_quote_only',
            'individual_buy_enabled',
            'discount_percentage', 'original_price', 'tag_label',
            'rent_stars',
            'is_machine_eligible', 'stock_status', 'display_order',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $out[$key] = $payload[$key];
            }
        }

        return $out;
    }

    private function refresh(Product $product): Product
    {
        $product->refresh();
        $product->load(['category', 'images', 'specs', 'prices']);
        $product->loadCount([
            'images',
            'orderItems as orders_count' => function (Builder $q): void {
                $q->whereHas('order', function (Builder $orderQuery): void {
                    $orderQuery->whereIn('status', self::ACTIVE_ORDER_STATUSES)
                        ->whereNull('deleted_at');
                });
            },
        ]);

        return $product;
    }
}
