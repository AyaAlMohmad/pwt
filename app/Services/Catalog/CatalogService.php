<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only catalog queries used by the customer-facing endpoints.
 *
 * The service is intentionally thin: there is no domain state to mutate
 * in Phase 6 (admin-side CRUD lands in Phase 16). All query construction,
 * eager-loading and locale-aware JSON filtering lives here so controllers
 * stay ≤ 30 lines.
 *
 * Category mapping decisions for the seeded 16 unit types are documented
 * in {@see ProductCatalogSeeder} per
 * docs/architecture/catalog.md § 8.6:
 *   - "Counter-top Filters"     ← default (most SKUs)
 *   - "Under-sink Filters"      ← UnderSink
 *   - "Whole-house Filtration"  ← PW90
 *   - "Commercial"              ← BWT, BV-M, BV-R (also is_quote_only=true)
 *   - "Spare Parts"             ← (empty in MVP)
 */
final class CatalogService
{
    /**
     * Default currency when the request omits `?currency=`. AED is the
     * home-market currency per CLAUDE.md § 6 and the BRD.
     */
    private const DEFAULT_CURRENCY = 'AED';

    /**
     * Default page size for `/products`. CLAUDE.md § 5.4 specifies 15.
     */
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Hard cap on `per_page`. Enforced by the Form Request as well.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Return the active categories, ordered by display_order then by
     * the localized name (so a category list reads consistently for
     * AR and EN locales).
     *
     * @return Collection<int, Category>
     */
    public function listCategories(?string $search = null): Collection
    {
        $locale = app()->getLocale();

        $query = Category::query()->visibleToCustomers();

        if ($search !== null && $search !== '') {
            $this->applyLocalizedSearch($query, $search, $locale, ['name']);
        }

        // Orders by the JSON-extracted localized name. The bound `$path`
        // is restricted by SetLocale middleware (en|ar only), but we
        // still bind it rather than concatenate as defense in depth.
        $path = $this->jsonPath($locale);

        return $query
            ->orderBy('display_order')
            ->orderByRaw('JSON_UNQUOTE(JSON_EXTRACT(name, ?)) ASC', [$path])
            ->orderBy('id')
            ->get();
    }

    /**
     * Fetch one active category by id, with the active-product count
     * preloaded.
     *
     * Throws ModelNotFoundException → translates to 404 ERR_NOT_FOUND
     * via the global exception handler.
     */
    public function findCategory(int $id): Category
    {
        /** @var Category $category */
        $category = Category::query()
            ->visibleToCustomers()
            ->withCount(['products' => fn (Builder $q) => $q->where('is_active', true)])
            ->findOrFail($id);

        return $category;
    }

    /**
     * Paginated product list with eager-loaded category, primary image,
     * and active prices. The customer-supplied filters drive WHERE/ORDER
     * clauses; the visibility scope and resolved currency are applied
     * server-side and cannot be overridden by the client.
     *
     * Filters (all optional):
     *   - category_id (int, must be active — checked in Form Request)
     *   - term        ('buy'|'rent'|'foc')
     *   - search      (≤ 100 chars, matches localized name / descriptions)
     *   - min_price   (decimal, in resolved currency)
     *   - max_price   (decimal, in resolved currency)
     *   - currency    ('AED'|'SAR'|'QAR'|'KWD'|'BHD'|'OMR', default AED)
     *   - sort        ('price'|'created_at'|'name', default 'created_at')
     *   - order       ('asc'|'desc')
     *   - featured    (bool — restricts to is_featured=true)
     *
     * @param  array<string, mixed>  $filters  Validated query payload.
     * @return LengthAwarePaginator<int, Product>
     */
    public function listProducts(array $filters): LengthAwarePaginator
    {
        $locale = app()->getLocale();
        $currency = $this->resolveCurrency($filters);
        $perPage = $this->resolvePerPage($filters);
        $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'created_at';
        $order = $this->resolveOrder($filters, $sort);

        $query = Product::query()
            ->visibleToCustomers()
            ->with([
                'category',
                'images',
                'activePrices' => fn ($q) => $q->where('currency', $currency),
            ]);

        if (isset($filters['category_id']) && is_numeric($filters['category_id'])) {
            $query->inCategory((int) $filters['category_id']);
        }

        if (! empty($filters['featured'])) {
            $query->featured();
        }

        if (! empty($filters['tag_label']) && is_string($filters['tag_label'])) {
            $query->where('tag_label', $filters['tag_label']);
        }

        $this->applyPriceAndTermFilters($query, $filters, $currency);

        if (! empty($filters['search']) && is_string($filters['search'])) {
            $this->applyLocalizedSearch(
                $query,
                $filters['search'],
                $locale,
                ['name', 'short_description', 'description'],
            );
        }

        $this->applySort($query, $sort, $order, $currency, $locale);

        return $query->paginate($perPage)->appends($filters);
    }

    /**
     * Fetch one active product by id with relations eager-loaded for
     * `ProductDetailResource`.
     *
     * The embedded category includes a `products_count` of ACTIVE
     * products only, per architecture spec § 4.3 (CategoryResource
     * emits the count via `whenCounted('products')`).
     */
    public function findProduct(int $id): Product
    {
        /** @var Product $product */
        $product = Product::query()
            ->visibleToCustomers()
            ->with([
                'category' => fn ($q) => $q->withCount([
                    'products' => fn (Builder $q2) => $q2->where('is_active', true),
                ]),
                'images',
                'specs',
                'activePrices',
            ])
            ->findOrFail($id);

        return $product;
    }

    /**
     * Return active products tagged as best-seller, ordered by display_order
     * then by most recently created.
     *
     * @return Collection<int, Product>
     */
    public function bestSellers(): Collection
    {
        return Product::query()
            ->visibleToCustomers()
            ->where('tag_label', 'best_seller')
            ->with(['category', 'images', 'activePrices'])
            ->orderBy('display_order')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Resolve the currency from the validated filter payload, defaulting
     * to AED when absent.
     *
     * @param  array<string, mixed>  $filters
     */
    public function resolveCurrency(array $filters): string
    {
        $raw = $filters['currency'] ?? null;

        return is_string($raw) && $raw !== ''
            ? strtoupper($raw)
            : self::DEFAULT_CURRENCY;
    }

    /**
     * Resolve and clamp the page size from the validated payload.
     *
     * @param  array<string, mixed>  $filters
     */
    private function resolvePerPage(array $filters): int
    {
        $raw = $filters['per_page'] ?? null;
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_PER_PAGE;

        return max(1, min(self::MAX_PER_PAGE, $value));
    }

    /**
     * Resolve the sort direction with sensible per-sort defaults.
     *
     * @param  array<string, mixed>  $filters
     */
    private function resolveOrder(array $filters, string $sort): string
    {
        $raw = $filters['order'] ?? null;

        if (is_string($raw) && in_array(strtolower($raw), ['asc', 'desc'], true)) {
            return strtolower($raw);
        }

        return match ($sort) {
            'price', 'name' => 'asc',
            default => 'desc',
        };
    }

    /**
     * Apply min_price / max_price / term filters. All three constrain
     * the products table through `whereHas` on the active prices
     * relation; we cannot just JOIN because a product may have multiple
     * price rows and we want products with ANY matching row, not all.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyPriceAndTermFilters(Builder $query, array $filters, string $currency): void
    {
        $term = is_string($filters['term'] ?? null) ? $filters['term'] : null;
        $minPrice = isset($filters['min_price']) && is_numeric($filters['min_price'])
            ? (float) $filters['min_price']
            : null;
        $maxPrice = isset($filters['max_price']) && is_numeric($filters['max_price'])
            ? (float) $filters['max_price']
            : null;

        if ($term === null && $minPrice === null && $maxPrice === null) {
            return;
        }

        $query->whereHas('activePrices', function (Builder $q) use ($currency, $term, $minPrice, $maxPrice): void {
            $q->where('currency', $currency);
            if ($term !== null) {
                $q->where('term', $term);
            }
            if ($minPrice !== null) {
                $q->where('amount', '>=', $minPrice);
            }
            if ($maxPrice !== null) {
                $q->where('amount', '<=', $maxPrice);
            }
        });
    }

    /**
     * Apply ORDER BY based on the resolved sort key.
     *
     * `price` uses a correlated sub-select against the active prices in
     * the resolved currency. `name` uses the localized JSON path.
     * `created_at` is the default fallback.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, string $sort, string $order, string $currency, string $locale): void
    {
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        switch ($sort) {
            case 'price':
                // Correlated sub-select: MIN(amount) for each product's
                // active prices in the resolved currency. The currency
                // is parameter-bound to prevent injection.
                $query->orderByRaw(
                    '(SELECT MIN(amount) FROM product_prices '
                    .'WHERE product_prices.product_id = products.id '
                    .'AND product_prices.is_active = 1 '
                    ."AND product_prices.currency = ?) $direction",
                    [$currency],
                );
                break;

            case 'name':
                $path = $this->jsonPath($locale);
                $query->orderByRaw('JSON_UNQUOTE(JSON_EXTRACT(name, ?)) '.$direction, [$path]);
                break;

            default:
                $query->orderBy('created_at', strtolower($direction));
                break;
        }

        $query->orderBy('id', 'asc'); // Stable tiebreaker.
    }

    /**
     * Apply a case-insensitive LIKE filter against the translatable
     * JSON columns for the active locale. The locale comes from the
     * SetLocale middleware (always 'en' or 'ar') and is always
     * parameter-bound rather than concatenated.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     */
    private function applyLocalizedSearch(Builder $query, string $search, string $locale, array $columns): void
    {
        $path = $this->jsonPath($locale);
        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($columns, $path, $like): void {
            foreach ($columns as $column) {
                $q->orWhereRaw(
                    "JSON_UNQUOTE(JSON_EXTRACT($column, ?)) LIKE ?",
                    [$path, $like],
                );
            }
        });
    }

    /**
     * Build the MySQL JSON path for the active locale. Restricted to
     * `en` / `ar` by SetLocale; bound rather than concatenated.
     */
    private function jsonPath(string $locale): string
    {
        return '$."'.$locale.'"';
    }
}
