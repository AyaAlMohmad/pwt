<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental payment schedules for machines with term = rent.
 *
 * One row per rented machine. Tracks the next payment due date,
 * the saved payment card to charge, and the recurring amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_rental_schedules', function (Blueprint $table): void {
            $table->bigIncrements('id');

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
            $table->string('frequency', 20)->default('monthly');

            $table->date('next_payment_due_at');
            $table->date('last_payment_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['status', 'next_payment_due_at'], 'rental_schedules_status_due_idx');
            $table->index(['user_id', 'status'], 'rental_schedules_user_status_idx');
            $table->index('machine_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_rental_schedules');
    }
};
