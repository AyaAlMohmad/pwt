<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'HealthResponse',
    required: ['success', 'message', 'data'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Service healthy'),
        new OA\Property(
            property: 'data',
            required: ['status', 'service', 'time'],
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(property: 'service', type: 'string', example: 'pwt-api'),
                new OA\Property(property: 'time', type: 'string', format: 'date-time', example: '2026-05-15T09:30:00+04:00'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    required: ['success', 'message'],
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            nullable: true,
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
        new OA\Property(property: 'code', type: 'string', example: 'ERR_VALIDATION', nullable: true),
    ],
    type: 'object',
)]
final class HealthResponse
{
    // Documentation-only.
}
