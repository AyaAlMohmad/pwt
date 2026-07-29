<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value application setting.
 *
 * `value` is a JSON column and may contain a scalar, array, or object.
 * Use {@see Setting::get()} for a defensive read; never read raw rows
 * straight into a response — pass through `PublicSettingsResource`
 * which applies the public allowlist.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property bool $is_public
 *
 * @method static Builder<static> public()
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
        'is_public',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Fetch a single setting value by key, returning the default when
     * missing. The value is decoded via the JSON cast on the model.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::query()->where('key', $key)->first();

        if ($row === null) {
            return $default;
        }

        return $row->value ?? $default;
    }
}
