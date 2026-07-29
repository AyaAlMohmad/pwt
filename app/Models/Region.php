<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Region (emirate / province / governorate) reference model.
 *
 * @property int $id
 * @property int $country_id
 * @property string|null $code
 * @property array<string, string>|string $name
 * @property bool $is_active
 * @property-read Country|null $country
 *
 * @method static Builder<static> active()
 */
final class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var list<string> */
    protected $fillable = [
        'country_id',
        'code',
        'name',
        'is_active',
    ];

    /** @var array<int, string> */
    public array $translatable = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
