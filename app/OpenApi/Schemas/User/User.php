<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\User;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    required: ['id', 'name', 'phone', 'phone_country_code', 'locale', 'is_active', 'roles'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Ahmed Al Rashidi'),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'ahmed@example.com'),
        new OA\Property(property: 'phone', type: 'string', example: '+971501234567'),
        new OA\Property(property: 'phone_country_code', type: 'string', example: '+971'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'ar'], example: 'en'),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['customer']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-05-15T09:30:00+00:00'),
    ],
    type: 'object',
)]
final class User
{
    // Documentation-only.
}
