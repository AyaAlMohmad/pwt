<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Rfq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rfq\CreateRfqRequest;
use App\Http\Resources\Api\V1\Rfq\RfqResource;
use App\Services\Rfq\RfqService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Public guest RFQ controller.
 *
 * Thin per CLAUDE.md § 15. Validation lives in the Form Request,
 * business logic in {@see RfqService}.
 */
final class RfqController extends Controller
{
    public function __construct(
        private readonly RfqService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/rfq',
        operationId: 'rfqStore',
        description: 'Submit a Request for Quotation. Public endpoint — no auth. Rate-limited at 5/min/IP.',
        summary: 'Submit RFQ',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateRfqRequest'),
        ),
        tags: ['RFQ'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/RfqResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateRfqRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $rfq = $this->service->submit(
            $payload,
            $request->ip(),
            $request->header('User-Agent') !== null ? (string) $request->header('User-Agent') : null,
        );

        return ApiResponse::success(
            new RfqResource($rfq),
            __('rfq.submitted'),
            201,
        );
    }
}
