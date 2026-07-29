<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit row capturing every gateway callback, every COD
 * initiate, and every system-driven payment cancellation.
 *
 * Rows are never updated; the table has `created_at` only (no
 * `updated_at`). `signature_valid=true` for verified webhooks; `null`
 * for non-webhook events. Rows with `signature_valid=false` do not
 * exist — the signature middleware rejects unsigned payloads before
 * any DB write.
 *
 * See docs/architecture/payments.md § 10.2.
 *
 * @property int $id
 * @property int|null $payment_id
 * @property string $type
 * @property string|null $source
 * @property string|null $gateway_event_id
 * @property array<string, mixed> $payload
 * @property bool|null $signature_valid
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property-read Payment|null $payment
 */
final class PaymentEvent extends Model
{
    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    protected $table = 'payment_events';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'type',
        'source',
        'gateway_event_id',
        'payload',
        'signature_valid',
        'processed_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            if ($event->created_at === null) {
                $event->created_at = Carbon::now();
            }
        });
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
