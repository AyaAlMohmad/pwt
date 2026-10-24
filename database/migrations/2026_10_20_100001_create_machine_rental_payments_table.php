<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual payment attempts against a machine rental schedule.
 *
 * One row per scheduled occurrence. Stripe off-session charges are
 * recorded here so the state machine is audit-friendly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_rental_payments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('machine_rental_schedule_id')
                ->constrained('machine_rental_schedules')
                ->restrictOnDelete();

            $table->foreignId('machine_id')
                ->constrained('machines')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('payment_card_id')
                ->nullable()
                ->constrained('payment_cards')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AED');
            $table->string('status', 20)->default('pending');

            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();

            $table->string('stripe_payment_intent_id', 120)->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'due_date'], 'rental_payments_status_due_idx');
            $table->index(['user_id', 'status'], 'rental_payments_user_status_idx');
            $table->index('machine_rental_schedule_id');
            $table->index('machine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_rental_payments');
    }
};
