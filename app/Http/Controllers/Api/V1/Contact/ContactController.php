<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Contact;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Contact\CreateContactLeadRequest;
use App\Http\Resources\Api\V1\Contact\ContactLeadResource;
use App\Services\Contact\ContactLeadService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Public guest contact-form controller (Phase 13).
 *
 * Single endpoint:
 *   POST /api/v1/contact
 *
 * Unauthenticated — guests + pre-login app users post lead submissions.
 * Rate-limited via the `contact-form` named limiter (5/min/IP).
 */
final class ContactController extends Controller
{
    public function __construct(
        private readonly ContactLeadService $service,
    ) {}

    #[OA\Post(
        path: '/api/v1/contact',
        operationId: 'contactStore',
        description: 'Submit a guest contact-form / lead. Public endpoint — no Sanctum. Rate-limited at 5/min/IP. Body field `message` is NEVER echoed back in the response.',
        summary: 'Submit a guest contact-form / lead',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateContactLeadRequest'),
        ),
        tags: ['Contact'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/ContactLeadResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(CreateContactLeadRequest $request): JsonResponse
    {
        /** @var array{
         *     name: string,
         *     email: string,
         *     subject: string,
         *     message: string,
         *     phone?: string|null,
         *     phone_country_code?: string|null,
         *     source?: string|null,
         *     type?: string|null,
         *     website?: string|null,
         * } $payload */
        $payload = $request->validated();

        $lead = $this->service->submit(
            $payload,
            $request->ip(),
            $request->header('User-Agent') !== null ? (string) $request->header('User-Agent') : null,
        );

        return ApiResponse::success(
            new ContactLeadResource($lead),
            __('contact.thank_you'),
            201,
        );
    }
}
