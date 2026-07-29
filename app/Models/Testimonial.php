<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TestimonialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/**
 * Customer testimonial displayed on the home page.
 *
 * Per docs/architecture/promotions.md § 4.4 / § 9.7 only `quote` is
 * translatable in Phase 7. `role` and `company` are plain strings.
 *
 * @property int $id
 * @property string $name
 * @property string|null $role
 * @property string|null $company
 * @property string|null $avatar_url
 * @property int $rating
 * @property array<string, string>|string $quote
 * @property int $display_order
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> ordered()
 */
final class Testimonial extends Model
{
    /** @use HasFactory<TestimonialFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'testimonials';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'role',
        'company',
        'avatar_url',
        'rating',
        'quote',
        'display_order',
        'is_active',
    ];

    /** @var array<int, string> */
    public array $translatable = ['quote'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'role',
                'company',
                'avatar_url',
                'rating',
                'quote',
                'display_order',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('testimonial');
    }

    /**
     * Customer visibility scope.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Order by `display_order` then `id` for stable pagination.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    protected static function newFactory(): TestimonialFactory
    {
        return TestimonialFactory::new();
    }
}
