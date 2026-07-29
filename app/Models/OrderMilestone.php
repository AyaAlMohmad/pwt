<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MilestoneStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $step_key
 * @property string $label
 * @property string|null $description
 * @property MilestoneStatus $status
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $scheduled_from_at
 * @property \Illuminate\Support\Carbon|null $scheduled_to_at
 * @property array<string, mixed>|null $metadata
 * @property int $sort_order
 */
final class OrderMilestone extends Model
{
    protected $table = 'order_milestones';

    protected $fillable = [
        'order_id',
        'step_key',
        'label',
        'description',
        'status',
        'completed_at',
        'scheduled_from_at',
        'scheduled_to_at',
        'metadata',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => MilestoneStatus::class,
            'completed_at' => 'datetime',
            'scheduled_from_at' => 'date',
            'scheduled_to_at' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
