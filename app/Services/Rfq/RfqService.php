<?php

declare(strict_types=1);

namespace App\Services\Rfq;

use App\Events\Rfq\RfqCreated;
use App\Models\RfqRequest;
use Illuminate\Support\Facades\DB;

/**
 * RFQ (Request for Quotation) orchestrator.
 *
 * Public guest form. Simplified fields: company_name, email, phone, notes.
 */
final class RfqService
{
    /**
     * Submit a new RFQ from a public guest form.
     *
     * @param  array{
     *     company_name: string,
     *     email: string,
     *     phone?: string|null,
     *     notes?: string|null,
     *     website?: string|null,
     * }  $payload
     */
    public function submit(array $payload, ?string $ip, ?string $userAgent): RfqRequest
    {
        $honeypot = isset($payload['website']) ? trim((string) $payload['website']) : '';
        if ($honeypot !== '') {
            return $this->buildPhantom($payload);
        }

        $attributes = [
            'company_name' => (string) $payload['company_name'],
            'email' => mb_strtolower((string) $payload['email']),
            'phone' => isset($payload['phone']) && $payload['phone'] !== ''
                ? (string) $payload['phone']
                : null,
            'notes' => isset($payload['notes']) && $payload['notes'] !== ''
                ? (string) $payload['notes']
                : null,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'status' => 'pending',
            'processed' => false,
        ];

        return DB::transaction(function () use ($attributes): RfqRequest {
            /** @var RfqRequest $rfq */
            $rfq = RfqRequest::query()->create($attributes);

            $rfqId = (int) $rfq->id;
            DB::afterCommit(static function () use ($rfqId): void {
                event(new RfqCreated(rfqId: $rfqId));
            });

            return $rfq;
        });
    }

    /**
     * Build a non-persisted RfqRequest for honeypot responses.
     *
     * @param  array{
     *     company_name: string,
     *     email: string,
     *     phone?: string|null,
     *     notes?: string|null,
     * }  $payload
     */
    private function buildPhantom(array $payload): RfqRequest
    {
        $rfq = new RfqRequest;
        $rfq->forceFill([
            'id' => 0,
            'company_name' => (string) $payload['company_name'],
            'email' => mb_strtolower((string) $payload['email']),
            'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'status' => 'pending',
            'processed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rfq;
    }
}
