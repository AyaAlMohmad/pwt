<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BannerPlacement;
use App\Http\Resources\Api\V1\Promotions\BannerResource;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Translatable\HasTranslations;

/**
 * Homepage / landing-page banner.
 *
 * Customer endpoints scope every query to {@see scopeActive()} so
 * inactive / out-of-window rows never leak
 * (docs/architecture/promotions.md § 1.4). `image_url_ar` is an optional
 * RTL override — see {@see BannerResource}.
 *
 * @property int $id
 * @property string $code
 * @property BannerPlacement $placement
 * @property array<string, string>|string|null $title
 * @property array<string, string>|string|null $subtitle
 * @property string $image_url
 * @property string|null $image_url_ar
 * @property array<string, string>|string|null $cta_label
 * @property string|null $cta_url
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int $display_order
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forPlacement(BannerPlacement $placement)
 */
final class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use HasFactory;

    use HasTranslations;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'banners';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'placement',
        'title',
        'subtitle',
        'image_url',
        'image_url_ar',
        'cta_label',
        'cta_url',
        'starts_at',
        'ends_at',
        'display_order',
        'is_active',
    ];

    /** @var array<int, string> */
    public array $translatable = ['title', 'subtitle', 'cta_label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => BannerPlacement::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'code',
                'placement',
                'title',
                'subtitle',
                'image_url',
                'image_url_ar',
                'cta_label',
                'cta_url',
                'starts_at',
                'ends_at',
                'display_order',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('banner');
    }

    /**
     * Customer-visibility scope — `is_active = true` AND inside any
     * configured time window. Null start/end mean "no bound".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(static function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(static function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    /**
     * Restrict to a single placement slot. Passing `null` is a no-op so
     * the controller can call this unconditionally with the filter value
     * from the Form Request.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForPlacement(Builder $query, ?BannerPlacement $placement): Builder
    {
        if ($placement === null) {
            return $query;
        }

        return $query->where('placement', $placement->value);
    }

    protected static function newFactory(): BannerFactory
    {
        return BannerFactory::new();
    }
}
