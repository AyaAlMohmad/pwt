<?php

use Illuminate\Foundation\Inspiring;
use App\Enums\MaintenanceIssueType;
use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Machine;
use App\Models\MaintenanceRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('maintenance:generate-due-requests', function (): void {
    $threshold = now()->addDays(10)->toDateString();
    $created = 0;

    Machine::query()
        ->active()
        ->whereNotNull('next_maintenance_due_at')
        ->where('next_maintenance_due_at', '<=', $threshold)
        ->chunk(100, function ($machines) use (&$created): void {
            foreach ($machines as $machine) {
                $existing = MaintenanceRequest::query()
                    ->where('machine_id', $machine->id)
                    ->whereDate('preferred_date', $machine->next_maintenance_due_at->toDateString())
                    ->whereNotIn('status', [
                        MaintenanceStatus::Completed->value,
                        MaintenanceStatus::Cancelled->value,
                    ])
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                MaintenanceRequest::create([
                    'user_id' => $machine->user_id,
                    'machine_id' => $machine->id,
                    'issue_type' => MaintenanceIssueType::FilterChange->value,
                    'description' => 'Automatic recurring maintenance request. Due date: '.$machine->next_maintenance_due_at->toDateString().'.',
                    'status' => MaintenanceStatus::New->value,
                    'priority' => MaintenancePriority::Medium->value,
                    'preferred_date' => $machine->next_maintenance_due_at,
                ]);

                $created++;
            }
        });

    $this->info("{$created} maintenance request(s) created.");
})->purpose('Create maintenance requests for machines due within 10 days.');

Schedule::command('maintenance:generate-due-requests')->dailyAt('01:00');
