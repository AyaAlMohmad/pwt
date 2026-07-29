<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Admin\Auth;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AdminLoginRequest',
    required: ['email', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@pwt.local'),
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Password123!'),
        new OA\Property(property: 'remember', type: 'boolean', example: false, nullable: true, description: 'Reserved for a future long-lived dashboard session; ignored in MVP.'),
    ],
    type: 'object',
)]
final class AdminLoginRequest
{
    // Documentation-only.
}
