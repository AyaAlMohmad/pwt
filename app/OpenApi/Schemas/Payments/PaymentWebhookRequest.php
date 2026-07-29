<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas\Payments;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentWebhookRequest',
    description: 'Gateway-specific. Documents canonical fields for PayFort, Stripe, and the stub driver. Additional properties permitted.',
    properties: [
        new OA\Property(property: 'transaction_id', type: 'string', example: 'stub_a1b2c3d4'),
        new OA\Property(property: 'status', type: 'string', example: 'success'),
        new OA\Property(property: 'amount', type: 'string', example: '2362.50'),
        new OA\Property(property: 'currency', type: 'string', example: 'AED'),
        new OA\Property(property: 'event_id', type: 'string', nullable: true),
        new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
        new OA\Property(property: 'fort_id', type: 'string', nullable: true, description: 'PayFort correlator'),
        new OA\Property(property: 'merchant_reference', type: 'string', nullable: true),
        new OA\Property(property: 'response_code', type: 'string', nullable: true),
        new OA\Property(property: 'signature', type: 'string', nullable: true, description: 'PayFort canonical signature (form field)'),
    ],
    type: 'object',
    additionalProperties: true,
)]
final class PaymentWebhookRequest
{
    // Documentation-only.
}
