<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RFQ (Request for Quotation) — public guest form.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $company_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $notes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $status
 * @property bool $processed
 * @property int|null $processed_by_user_id
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read User|null $processedBy
 */
final class RfqRequest extends Model
{
    /** @use HasFactory<\Database\Factories\RfqRequestFactory> */
    use HasFactory;

    protected $table = 'rfq_requests';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'company_name',
        'email',
        'phone',
        'notes',
        'ip',
        'user_agent',
        'status',
        'processed',
        'processed_by_user_id',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed' => 'bool',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    /**
     * Scope to RFQs belonging to a specific customer.
     *
     * @param Builder<RfqRequest> $query
     */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
