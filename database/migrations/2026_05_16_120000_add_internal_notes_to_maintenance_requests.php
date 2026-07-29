<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 — admin-only internal notes column on maintenance_requests.
 *
 * Never exposed on the customer-facing
 * `MaintenanceRequestDetailResource`. Returned on the new
 * `AdminMaintenanceRequestDetailResource`.
 *
 * See docs/architecture/admin-machines-maintenance.md § 8.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->text('internal_notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table): void {
            $table->dropColumn('internal_notes');
        });
    }
};
