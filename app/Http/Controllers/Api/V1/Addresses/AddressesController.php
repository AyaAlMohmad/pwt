<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Addresses;

use App\Exceptions\Addresses\AddressInUseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Addresses\StoreAddressRequest;
use App\Http\Requests\Api\V1\Addresses\UpdateAddressRequest;
use App\Http\Resources\Api\V1\Addresses\AddressResource;
use App\Models\Address;
use App\Models\User;
use App\Services\Addresses\AddressService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Customer address book controller.
 *
 * Routes are mounted under /api/v1/me/addresses; the parent group
 * applies `auth:sanctum`. Ownership is enforced via {@see
 * \App\Policies\AddressPolicy}.
 *
 * Each method stays ≤ 30 LOC per CLAUDE.md § 15: validation lives in
 * the Form Requests, business logic in {@see AddressService}, and the
 * controller only assembles the HTTP response envelope.
 */
final class AddressesController extends Controller
{
    public function __construct(
        private readonly AddressService $addresses,
    ) {}

    #[OA\Get(
        path: '/api/v1/me/addresses',
        operationId: 'meAddressesIndex',
        description: 'List the caller\'s addresses sorted by is_default DESC then created_at DESC. Unpaginated (max 10 per customer).',
        summary: 'List authenticated customer addresses',
        security: [['sanctum' => []]],
        tags: ['Addresses'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AddressCollectionResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Address::class);
        /** @var User $user */
        $user = $request->user();

        $addresses = $this->addresses->listForUser($user);

        return ApiResponse::success(
            AddressResource::collection($addresses)->resolve($request),
            __('addresses.retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/me/addresses',
        operationId: 'meAddressesStore',
        description: 'Create a new address. Setting is_default=true atomically demotes any previous default. The first address is auto-promoted to default. The 11th address is rejected with ERR_ADDRESS_LIMIT_REACHED.',
        summary: 'Create a new address',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAddressRequest'),
        ),
        tags: ['Addresses'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/AddressResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed (also ERR_REGION_COUNTRY_MISMATCH, ERR_ADDRESS_LIMIT_REACHED)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(StoreAddressRequest $request): JsonResponse
    {
        $this->authorize('create', Address::class);
        /** @var User $user */
        $user = $request->user();

        $address = $this->addresses->create($user, $request->validated());

        return ApiResponse::success(
            (new AddressResource($address))->resolve($request),
            __('addresses.created'),
            201,
        );
    }

    #[OA\Get(
        path: '/api/v1/me/addresses/{address}',
        operationId: 'meAddressesShow',
        description: 'Show a single owned address. Ownership failure returns 403 (not 404) per CLAUDE.md § 7.3.',
        summary: 'Show a single address',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 17)),
        ],
        tags: ['Addresses'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AddressResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request, Address $address): JsonResponse
    {
        $this->authorize('view', $address);
        $address->load(['country', 'region']);

        return ApiResponse::success(
            (new AddressResource($address))->resolve($request),
            __('addresses.shown'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/me/addresses/{address}',
        operationId: 'meAddressesUpdate',
        description: 'Partial update. Setting is_default=true atomically demotes any previous default. Omitted fields are unchanged; explicit-null clears nullable fields.',
        summary: 'Update an owned address',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 17)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAddressRequest'),
        ),
        tags: ['Addresses'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AddressResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed (also ERR_rent_period             _COUNTRY_MISMATCH)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateAddressRequest $request, Address $address): JsonResponse
    {
        $this->authorize('update', $address);

        $address = $this->addresses->update($address, $request->validated());

        return ApiResponse::success(
            (new AddressResource($address))->resolve($request),
            __('addresses.updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/me/addresses/{address}',
        operationId: 'meAddressesDestroy',
        description: 'Soft-delete an owned address. If it was the default, the next-most-recent surviving address is auto-promoted (returned as promoted_default_id). Phase 8 will add the ERR_ADDRESS_IN_USE guard.',
        summary: 'Delete an owned address',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'address', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 17)),
        ],
        tags: ['Addresses'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/AddressDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Address in use (Phase 8 guard)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Address $address): JsonResponse
    {
        $this->authorize('delete', $address);

        // Phase 8: once the orders table exists, isAddressInUse() will
        // return true when an active order references this address;
        // for now it always returns false, so the 409 branch is
        // dead-code-but-present until Orders ships.
        if ($this->addresses->isAddressInUse($address)) {
            throw AddressInUseException::create();
        }

        $promotedId = $this->addresses->delete($address);

        return ApiResponse::success([
            'id' => $address->id,
            'deleted_at' => $address->deleted_at?->toIso8601String(),
            'promoted_default_id' => $promotedId,
        ], __('addresses.deleted'));
    }
}
