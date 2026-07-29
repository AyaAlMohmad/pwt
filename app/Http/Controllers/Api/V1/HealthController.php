<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

final class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/v1/health',
        operationId: 'getHealth',
        description: 'Returns a simple envelope confirming the API is running. Used by load balancers, agents, and the verification pipeline.',
        summary: 'Service liveness probe',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is healthy',
                content: new OA\JsonContent(ref: '#/components/schemas/HealthResponse'),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(
            [
                'status' => 'ok',
                'service' => 'pwt-api',
                'time' => now()->toIso8601String(),
            ],
            __('messages.service_healthy'),
        );
    }
}
