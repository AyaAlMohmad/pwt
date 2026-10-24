<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rentals\MachineRentalPaymentService;
use Illuminate\Console\Command;

final class SendMachineRentalReminders extends Command
{
    protected $signature = 'rental:send-reminders {--days=5 : Days before due date to send reminder}';

    protected $description = 'Send reminder notifications to tenants whose rental payment is approaching.';

    public function handle(MachineRentalPaymentService $service): int
    {
        $days = (int) $this->option('days');
        $sent = $service->sendReminders($days);

        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
