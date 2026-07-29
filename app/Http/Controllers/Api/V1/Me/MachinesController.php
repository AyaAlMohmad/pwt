<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Maintenance\ListMachinesRequest;
use App\Http\Resources\Api\V1\Maintenance\MachineDetailResource;
use App\Http\Resources\Api\V1\Maintenance\MachineResource;
use App\Models\Machine;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Customer-facing Machines controller (`/api/v1/me/machines`).
 *
 * Read-only: customers see their own units. Admin Phase 18 endpoints
 * for create / edit live under `/api/v1/admin/machines/*`.
 */
final class MachinesController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me/machines',
        operationId: 'meMachinesIndex',
        description: "List the authenticated customer's installed machines. Filters: search, needs_maintenance, country_code, is_active, status (rented/purchased). Paginated (default 15, max 100). Sort by installed_at, next_maintenance_due_at, created_at, serial_number, product_name, or name.",
        summary: 'List my machines',
        security: [['sanctum' => []]],
        tags: ['Machines'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'search', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', maxLength: 120)),
            new OA\Parameter(name: 'needs_maintenance', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'country_code', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', minLength: 2, maxLength: 2)),
            new OA\Parameter(name: 'is_active', in: 'query', required: false,
                schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'status', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['rented', 'purchased'])),
            new OA\Parameter(name: 'sort', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['installed_at', 'next_maintenance_due_at', 'created_at', 'serial_number', 'product_name', 'name'])),
            new OA\Parameter(name: 'order', in: 'query', required: false,
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MachineCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(ListMachinesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('viewAny', Machine::class);

        $filters = $request->validated();
        $page = (int) ($request->query('page') ?? 1);
        $perPage = (int) ($request->query('per_page') ?? 15);

        $stats = $this->buildStats($user);
        $paginator = $this->buildIndexQuery($user, $filters)->paginate(
            perPage: $perPage,
            page: $page,
        );

        $machines = MachineResource::collection($paginator)->resolve();

        return ApiResponse::success(
            [
                'stats' => $stats,
                'machines' => $machines,
            ],
            __('maintenance.machines_retrieved'),
            200,
            ['pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ]],
        );
    }

    #[OA\Get(
        path: '/api/v1/me/machines/{id}',
        operationId: 'meMachinesShow',
        description: 'Show a single machine owned by the caller. Includes the latest 50 maintenance logs and aggregate counts. Ownership failure returns 403.',
        summary: 'Show machine detail',
        security: [['sanctum' => []]],
        tags: ['Machines'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 423)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MachineDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        /** @var Machine $machine */
        $machine = Machine::query()->findOrFail($id);
        $this->authorize('view', $machine);

        $machine->load([
            'product.images',
            'product.activePrices',
            'address.country',
            'address.region',
            'maintenanceLogs' => fn ($q) => $q->orderByDesc('scheduled_at')
                ->orderByDesc('id')
                ->limit(50),
        ])->loadCount([
            'maintenanceLogs as maintenance_logs_count',
            'maintenanceRequests as maintenance_requests_count' => fn ($q) => $q
                ->whereIn('status', ['new', 'assigned', 'in_progress']),
        ]);

        return ApiResponse::success(
            new MachineDetailResource($machine),
            __('maintenance.machine_retrieved'),
        );
    }

    /**
     * Build aggregate stats for the customer's machines.
     *
     * @return array<string, int>
     */
    private function buildStats(User $user): array
    {
        $query = Machine::query()->forUser((int) $user->id)->where('machines.is_active', true);

        return [
            'total' => (clone $query)->count(),
            'rented' => (clone $query)->where('term', 'rent')->count(),
            'purchased' => (clone $query)->where('term', 'buy')->count(),
        ];
    }

    /**
     * Build the customer-scoped index query, applying filters + sort.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Machine>
     */
    private function buildIndexQuery(User $user, array $filters): Builder
    {
        $query = Machine::query()
            ->with(['product.images', 'product.activePrices', 'address.country', 'order.items'])
            ->forUser((int) $user->id);

        $isActive = isset($filters['is_active'])
            ? filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $query->where('machines.is_active', $isActive);

        if (! empty($filters['country_code']) && is_string($filters['country_code'])) {
            $query->where('country_code', strtolower($filters['country_code']));
        }

        if (! empty($filters['status']) && is_string($filters['status'])) {
            $termValue = match ($filters['status']) {
                'rented' => 'rent',
                'purchased' => 'buy',
                default => null,
            };
            if ($termValue !== null) {
                $query->where('term', $termValue);
            }
        }

        if (! empty($filters['search']) && is_string($filters['search'])) {
            $term = trim($filters['search']);
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('serial_number', 'like', $like)
                    ->orWhere('location_label', 'like', $like)
                    ->orWhereHas('product', fn (Builder $p) => $p->where('code', 'like', $like));
            });
        }

        $needsMaintenance = isset($filters['needs_maintenance'])
            ? filter_var($filters['needs_maintenance'], FILTER_VALIDATE_BOOLEAN)
            : null;
        if ($needsMaintenance === true) {
            $query->dueWithinDays(30);
        }

        $sort = isset($filters['sort']) && is_string($filters['sort'])
            ? $filters['sort']
            : 'next_maintenance_due_at';
        $order = isset($filters['order']) && is_string($filters['order'])
            ? $filters['order']
            : ($sort === 'next_maintenance_due_at' ? 'asc' : 'desc');

        if ($sort === 'product_name' || $sort === 'name') {
            $query->join('products', 'machines.product_id', '=', 'products.id')
                ->orderBy('products.name', $order)
                ->orderBy('machines.id', 'desc')
                ->select('machines.*');
        } else {
            $query->orderBy($sort, $order)->orderBy('id', 'desc');
        }

        return $query;
    }
}
