<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Services\Notifications\TemplateRenderer;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Copy template for a notification event.
 *
 * One row per (`key`, `channel`) pair — e.g.
 * (`order.confirmed`, `in_app`). Subject + body are translatable JSON
 * blobs. Body supports `{{placeholder}}` substitution via
 * {@see TemplateRenderer}.
 *
 * Editable at runtime by admin (Phase 22).
 *
 * @property int $id
 * @property string $key
 * @property NotificationChannel $channel
 * @property array<string, string>|string $subject
 * @property array<string, string>|string $body
 * @property array<int, string>|null $placeholders
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forKey(string $key)
 */
final class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    use HasTranslations;

    protected $table = 'notification_templates';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'channel',
        'subject',
        'body',
        'placeholders',
        'is_active',
    ];

    /** @var list<string> */
    public array $translatable = ['subject', 'body'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }
}
