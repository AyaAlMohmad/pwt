<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rentals\MachineRentalPaymentService;
use Illuminate\Console\Command;

final class ProcessMachineRentalPayments extends Command
{
    protected $signature = 'rental:process-payments';

    protected $description = 'Process automatic Stripe charges for machine rental payments due today.';

    public function handle(MachineRentalPaymentService $service): int
    {
        $processed = $service->processDuePayments();

        $this->info("{$processed} payment(s) processed.");

        return self::SUCCESS;
    }
}
