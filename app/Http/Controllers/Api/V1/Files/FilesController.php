<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Files;

use App\Enums\FileType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Files\UploadFileRequest;
use App\Http\Resources\Api\V1\Files\FileResource;
use App\Models\File;
use App\Services\Files\FileStorageService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FilesController extends Controller
{
    public function __construct(
        private readonly FileStorageService $files,
    ) {}

    #[OA\Post(
        path: '/api/v1/files',
        operationId: 'filesUpload',
        description: 'Uploads a single image (jpeg/png/webp) or PDF up to 5 MB. The file is owned by the caller and is persisted alongside its mime, size, and SHA-256 checksum. Rate limit: 30 uploads per hour per user.',
        summary: 'Upload a file',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/FileUploadRequest'),
            ),
        ),
        tags: ['Files'],
        responses: [
            new OA\Response(response: 201, description: 'Created',
                content: new OA\JsonContent(ref: '#/components/schemas/FileUploadResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 413, description: 'Payload too large',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(UploadFileRequest $request): JsonResponse
    {
        $type = FileType::from((string) $request->input('type'));
        /** @var UploadedFile $upload */
        $upload = $request->file('file');

        $file = $this->files->store($upload, $request->user(), $type);

        return ApiResponse::success(
            (new FileResource($file))->resolve(),
            __('files.uploaded'),
            201,
        );
    }

    #[OA\Delete(
        path: '/api/v1/files/{id}',
        operationId: 'filesDelete',
        description: 'Soft-deletes an owned file. The physical artefact is purged asynchronously by a queued job.',
        summary: 'Delete an owned file',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 12)),
        ],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'Deleted',
                content: new OA\JsonContent(ref: '#/components/schemas/FileDeleteResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden — not the owner',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(int $id): JsonResponse
    {
        /** @var File $file */
        $file = File::query()->findOrFail($id);

        $this->authorize('delete', $file);

        $this->files->softDelete($file);

        return ApiResponse::success(null, __('files.deleted'));
    }

    #[OA\Get(
        path: '/api/v1/files/{uuid}/download',
        operationId: 'filesDownload',
        description: 'Stream a stored file to the caller. Owners (or admins with file view permissions) may call directly with a Sanctum bearer token; anyone holding a valid signed URL may also call. Public files may also be served via this endpoint for a uniform URL pattern.',
        summary: 'Download a file by UUID',
        security: [['sanctum' => []], []],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        tags: ['Files'],
        responses: [
            new OA\Response(response: 200, description: 'OK — binary stream'),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function download(Request $request, string $uuid): StreamedResponse|JsonResponse
    {
        /** @var File|null $file */
        $file = File::query()->where('uuid', $uuid)->first();

        if ($file === null) {
            return ApiResponse::error(__('files.not_found'), 404, null, 'ERR_NOT_FOUND');
        }

        // Accept EITHER an authenticated owner/admin (Sanctum bearer)
        // OR a valid signed URL. Public files pass either way. We
        // explicitly resolve the `sanctum` guard so the bearer token is
        // honoured even though this route is not gated by the
        // `auth:sanctum` middleware (the signed-URL branch must work
        // anonymously).
        $hasValidSignature = $request->hasValidSignature();
        $actor = $request->user('sanctum');

        if (! $hasValidSignature) {
            if ($actor === null) {
                return ApiResponse::error(__('messages.unauthenticated'), 401, null, 'ERR_UNAUTHENTICATED');
            }

            if (! $actor->can('view', $file)) {
                return ApiResponse::error(__('files.forbidden'), 403, null, 'ERR_FORBIDDEN');
            }
        }

        return $this->files->streamDownload($file);
    }
}
