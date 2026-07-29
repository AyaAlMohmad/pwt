<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Rfq;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Rfq\AdminRfqResource;
use App\Models\RfqRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AdminRfqController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/rfq',
        operationId: 'adminRfqIndex',
        description: 'Paginated list of RFQ submissions.',
        summary: 'List RFQ submissions',
        security: [['sanctum' => []]],
        tags: ['Admin / RFQ'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'processed', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 160)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminRfqCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAdmin', RfqRequest::class);

        $query = RfqRequest::query()->orderByDesc('created_at');

        if ($request->has('processed')) {
            $query->where('processed', (bool) $request->query('processed'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('company_name', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%')
                  ->orWhere('phone', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate(
            perPage: (int) $request->query('per_page', 15),
            page: (int) $request->query('page', 1),
        );

        return ApiResponse::success(
            AdminRfqResource::collection($paginator),
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/rfq/{id}',
        operationId: 'adminRfqShow',
        description: 'Single RFQ submission detail.',
        summary: 'Show RFQ submission',
        security: [['sanctum' => []]],
        tags: ['Admin / RFQ'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminRfqDetailResponse')),
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
        $this->authorize('viewAnyAdmin', RfqRequest::class);

        $rfq = RfqRequest::query()->findOrFail($id);

        return ApiResponse::success(
            new AdminRfqResource($rfq),
        );
    }

    #[OA\Patch(
        path: '/api/v1/admin/rfq/{id}/processed',
        operationId: 'adminRfqMarkProcessed',
        description: 'Mark an RFQ as processed.',
        summary: 'Mark RFQ processed',
        security: [['sanctum' => []]],
        tags: ['Admin / RFQ'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminRfqDetailResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function markProcessed(Request $request, int $id): JsonResponse
    {
        $this->authorize('updateAdmin', RfqRequest::class);

        $rfq = RfqRequest::query()->findOrFail($id);

        $rfq->update([
            'processed' => true,
            'processed_by_user_id' => $request->user()?->id,
            'processed_at' => now(),
        ]);

        return ApiResponse::success(
            new AdminRfqResource($rfq),
            __('rfq.marked_processed'),
        );
    }
}
