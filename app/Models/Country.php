<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Country reference model.
 *
 * Authoritative store for ISO alpha-2 code, bilingual name, phone
 * country code, currency, and the default VAT rate applied to orders
 * billed in that country.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string>|string $name
 * @property string $phone_code
 * @property string $currency
 * @property string $vat_rate
 * @property bool $is_active
 *
 * @method static Builder<static> active()
 */
final class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'phone_code',
        'currency',
        'vat_rate',
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
            'vat_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit a query to active countries.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<Region, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class);
    }
}
