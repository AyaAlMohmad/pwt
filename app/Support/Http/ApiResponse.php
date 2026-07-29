<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Standard PWT API response envelope.
 *
 * Every JSON response returned by the API MUST go through one of these
 * helpers. See CLAUDE.md § 5.2 for the canonical envelope shape.
 */
final class ApiResponse
{
    /**
     * Build a success envelope.
     *
     * If a paginator is provided, pagination meta is auto-extracted into
     * `meta.pagination`. Any caller-supplied $meta is merged afterwards
     * so it can override pagination keys if truly needed.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        ?array $meta = null,
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message ?? __('messages.ok'),
        ];

        $resolved = self::resolveData($data);
        $payload['data'] = $resolved['data'];

        $combinedMeta = $resolved['meta'] ?? [];
        if ($meta !== null) {
            $combinedMeta = array_replace_recursive($combinedMeta, $meta);
        }

        if ($combinedMeta !== []) {
            $payload['meta'] = $combinedMeta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Build an error envelope.
     *
     * @param  array<string, array<int, string>|string>|null  $errors
     */
    public static function error(
        string $message,
        int $status = 400,
        ?array $errors = null,
        ?string $code = null,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    /**
     * Normalise the data payload and extract pagination meta when applicable.
     *
     * @return array{data: mixed, meta?: array<string, mixed>}
     */
    private static function resolveData(mixed $data): array
    {
        if ($data instanceof LengthAwarePaginator) {
            return [
                'data' => $data->items(),
                'meta' => ['pagination' => self::paginationMeta($data)],
            ];
        }

        if ($data instanceof ResourceCollection) {
            $resource = $data->resource;
            if ($resource instanceof LengthAwarePaginator) {
                return [
                    'data' => $data->collection->all(),
                    'meta' => ['pagination' => self::paginationMeta($resource)],
                ];
            }
        }

        if ($data instanceof JsonResource) {
            return ['data' => $data->resolve()];
        }

        return ['data' => $data];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int|null>
     */
    private static function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
