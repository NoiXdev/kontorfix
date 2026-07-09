<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $driver
 * @property string|null $key
 * @property string|null $secret
 * @property string|null $region
 * @property string|null $bucket
 * @property string|null $endpoint
 * @property string|null $url
 * @property bool $use_path_style
 */
class StorageSetting extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'driver',
        'key',
        'secret',
        'region',
        'bucket',
        'endpoint',
        'url',
        'use_path_style',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'use_path_style' => 'bool',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create(['driver' => 'local']));
    }
}
