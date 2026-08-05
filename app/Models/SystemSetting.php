<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $registration_enabled
 */
class SystemSetting extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_enabled',
    ];

    protected $attributes = [
        'registration_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_enabled' => 'bool',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create());
    }
}
