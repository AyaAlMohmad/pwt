<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpPurpose;
use Database\Factories\OtpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One-time password for register / login / reset flows.
 *
 * Plaintext codes are NEVER persisted — `code_hash` holds the bcrypt of
 * the 6-digit value. The plaintext is dispatched once via the SMS
 * driver and (in non-production envs) surfaced as a transient
 * `dev_code` attribute on the freshly created instance.
 *
 * @property int $id
 * @property string $public_id
 * @property string $identifier
 * @property OtpPurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Otp extends Model
{
    /** @use HasFactory<OtpFactory> */
    use HasFactory;

    protected $table = 'otps';

    /** @var list<string> */
    protected $fillable = [
        'public_id',
        'identifier',
        'purpose',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'ip',
    ];

    /** @var list<string> */
    protected $hidden = [
        'code_hash',
    ];

    /**
     * Transient, in-memory only: the plaintext code (non-prod only).
     * Never persisted. Surfaced to the controller for the `dev_otp`
     * response field in `local`, `testing`, `staging` envs.
     */
    public ?string $devCode = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Active = not consumed AND not expired AND attempts left.
     *
     * @param  Builder<Otp>  $query
     * @return Builder<Otp>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    /**
     * Restrict to a given identifier (E.164 phone) + purpose.
     *
     * @param  Builder<Otp>  $query
     * @return Builder<Otp>
     */
    public function scopeForIdentifier(Builder $query, string $identifier, OtpPurpose $purpose): Builder
    {
        return $query
            ->where('identifier', $identifier)
            ->where('purpose', $purpose->value);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function markConsumed(): void
    {
        $this->forceFill(['consumed_at' => now()])->save();
    }

    public function incrementAttempts(): void
    {
        $this->increment('attempts');
    }

    protected static function newFactory(): OtpFactory
    {
        return OtpFactory::new();
    }
}
