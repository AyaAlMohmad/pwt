<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactLeadSource;
use App\Enums\ContactLeadType;
use Database\Factories\ContactLeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Guest contact-form submission.
 *
 * Phase 13 — write-only from the customer side; Phase 22 will add
 * admin list / mark-processed surfaces. No soft deletes per CLAUDE.md
 * § 6 (the table is not in the soft-delete list).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $phone_country_code
 * @property string $subject
 * @property string $message
 * @property ContactLeadSource $source
 * @property ContactLeadType $type
 * @property string|null $ip
 * @property string|null $user_agent
 * @property bool $processed
 * @property int|null $processed_by_user_id
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $processedBy
 */
final class ContactLead extends Model
{
    /** @use HasFactory<ContactLeadFactory> */
    use HasFactory;

    protected $table = 'contact_leads';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone_country_code',
        'subject',
        'message',
        'source',
        'type',
        'ip',
        'user_agent',
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
            'source' => ContactLeadSource::class,
            'type' => ContactLeadType::class,
            'processed' => 'bool',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Phase 22 admin who marked this lead processed.
     *
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
