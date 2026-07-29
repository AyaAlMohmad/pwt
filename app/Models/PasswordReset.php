<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResetChannel;
use Database\Factories\PasswordResetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Password-reset workflow row.
 *
 * Two channels:
 *   - email — `token_hash` holds bcrypt of a 64-hex token mailed to user.
 *   - phone — `token_hash` is NULL; a parallel `otps` row with
 *             purpose=Reset carries the proof.
 *
 * @property int $id
 * @property int|null $user_id
 * @property ResetChannel $channel
 * @property string $identifier
 * @property string|null $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class PasswordReset extends Model
{
    /** @use HasFactory<PasswordResetFactory> */
    use HasFactory;

    protected $table = 'password_resets';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'channel',
        'identifier',
        'token_hash',
        'expires_at',
        'consumed_at',
        'ip',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * Transient, in-memory only: the plaintext token / OTP for dev envs.
     */
    public ?string $devSecret = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ResetChannel::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Active = not consumed AND not expired.
     *
     * @param  Builder<PasswordReset>  $query
     * @return Builder<PasswordReset>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<PasswordReset>  $query
     * @return Builder<PasswordReset>
     */
    public function scopeForIdentifier(Builder $query, string $identifier, ResetChannel $channel): Builder
    {
        return $query
            ->where('identifier', $identifier)
            ->where('channel', $channel->value);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function markConsumed(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }

    protected static function newFactory(): PasswordResetFactory
    {
        return PasswordResetFactory::new();
    }
}
