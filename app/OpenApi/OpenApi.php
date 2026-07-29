<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Documentation-only class. Holds the root OpenAPI metadata for the API.
 */
#[OA\Info(
    version: '1.0.0',
    description: 'PWT Digital Platform REST API. All endpoints return the standard envelope { success, message, data, meta?, errors?, code? }. See CLAUDE.md § 5.2 for the contract.',
    title: 'PWT API',
    contact: new OA\Contact(name: 'PWT Engineering', email: 'engineering@pwt.local'),
)]
#[OA\Server(url: 'http://127.0.0.1:8000', description: 'Local development')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    description: 'Laravel Sanctum personal-access token. Send as `Authorization: Bearer <token>`.',
    bearerFormat: 'JWT',
    scheme: 'bearer',
)]
#[OA\Tag(name: 'Health', description: 'Liveness and metadata endpoints')]
#[OA\Tag(name: 'Reference', description: 'Public lookup data: countries, regions and whitelisted application settings.')]
#[OA\Tag(name: 'Auth', description: 'Customer authentication: OTP registration, login, password reset, logout.')]
#[OA\Tag(name: 'User', description: 'Authenticated customer profile (self-only).')]
#[OA\Tag(name: 'Addresses', description: 'Customer address book for delivery destinations. Each address belongs to one customer and is scoped by ownership.')]
#[OA\Tag(name: 'Catalog', description: 'Public read-only catalog of categories, products, images, specs, and prices. Inactive resources are filtered out of customer responses.')]
#[OA\Tag(name: 'Orders', description: 'Customer order placement, history, tracking, and cancellation. All monetary fields are recomputed server-side; idempotent on the optional Idempotency-Key header.')]
#[OA\Tag(name: 'Machines', description: 'Customer-owned installed filter units. Read-only for customers; admin Phase 18 manages create/edit. Includes maintenance logs and next-due date.')]
#[OA\Tag(name: 'Maintenance', description: 'Customer maintenance requests against owned machines. Submit, list, view, and cancel-from-new. Admin assign/progress/complete lives in Phase 18.')]
#[OA\Tag(name: 'Support', description: 'Authenticated customer support tickets — create, list, view thread, cancel. Internal admin notes are never returned to customers. Admin assign/reply lives in Phase 19.')]
#[OA\Tag(name: 'Contact', description: 'Public guest contact-form / lead capture. No authentication. Rate-limited at 5/min/IP. The submitted `message` is never echoed back in the response.')]
#[OA\Tag(name: 'Admin / Users', description: 'Admin lifecycle management for both admin and customer accounts: list, detail, partial update (incl. role + activation), soft-deactivate. super_admin gates writes; support has read-only access.')]
#[OA\Tag(name: 'Admin / Orders', description: 'Admin operations against the customer orders aggregate: list, detail, status transitions, expanded-window cancel, schedule installations, internal notes. Reuses the Phase 8 state machine.')]
#[OA\Tag(name: 'Admin / Reports', description: 'Admin reporting & export endpoints — read-only analytics (sales / orders / maintenance / customers) and streamed CSV/XLSX downloads. Capped at 10,000 rows per export with truncation header.')]
final class OpenApi
{
    // Intentionally empty — annotations only.
}
