<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Payment events (append-only audit trail).
 *
 * See docs/architecture/payments.md § 10.2.
 *
 * Every webhook delivery (including idempotent replays), every system
 * cancellation, and every COD initiate writes a row here. `signature_valid`
 * is `true` for verified webhooks; `null` for non-webhook events. Rows
 * with `signature_valid=false` never exist — the signature middleware
 * rejects unsigned bodies before reaching the service layer.
 *
 * The table is append-only (no `updated_at`). Hard-cascaded on
 * `payment_id` so a forcibly-deleted Payment drops its events together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Nullable to allow orphan-event rows when a signed webhook
            // references an unknown gateway_transaction_id (admin reviews
            // these in Phase 17 forensics). Cascaded on delete so a
            // forcibly-deleted Payment drops its events together.
            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->cascadeOnDelete();

            // `webhook` | `manual` | `system`.
            $table->string('type', 20);
            // `payfort` | `stub` | `cod` | `admin` | `system` | `customer`.
            $table->string('source', 20)->nullable();

            // Gateway event uuid (PayFort: `response_code-fort_id`; stub: synthetic).
            $table->string('gateway_event_id', 120)->nullable();

            $table->json('payload');

            $table->boolean('signature_valid')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'type'], 'payment_events_payment_type_idx');
            $table->index('gateway_event_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
