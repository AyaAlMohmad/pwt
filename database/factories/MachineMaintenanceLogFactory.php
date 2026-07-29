<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaintenanceLogSource;
use App\Enums\MaintenanceLogStatus;
use App\Models\Machine;
use App\Models\MachineMaintenanceLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineMaintenanceLog>
 */
final class MachineMaintenanceLogFactory extends Factory
{
    protected $model = MachineMaintenanceLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = now()->subDays(fake()->numberBetween(0, 365))->toDateString();

        return [
            'machine_id' => Machine::factory(),
            'status' => MaintenanceLogStatus::Completed->value,
            'scheduled_at' => $date,
            'completed_at' => $date,
            'technician_user_id' => null,
            'notes' => null,
            'created_by_user_id' => null,
            'source' => MaintenanceLogSource::Imported->value,
            'maintenance_request_id' => null,
        ];
    }

    public function scheduled(): self
    {
        return $this->state(fn (array $attrs): array => [
            'status' => MaintenanceLogStatus::Scheduled->value,
            'scheduled_at' => now()->addDays(14)->toDateString(),
            'completed_at' => null,
        ]);
    }

    public function completed(): self
    {
        return $this->state(function (array $attrs): array {
            $date = $attrs['scheduled_at'] ?? now()->subDay()->toDateString();

            return [
                'status' => MaintenanceLogStatus::Completed->value,
                'completed_at' => $date,
            ];
        });
    }

    public function imported(): self
    {
        return $this->state(fn (array $attrs): array => [
            'source' => MaintenanceLogSource::Imported->value,
            'notes' => 'Imported from production records',
        ]);
    }

    public function forMachine(Machine $machine): self
    {
        return $this->state(fn (array $attrs): array => [
            'machine_id' => $machine->id,
        ]);
    }
}
