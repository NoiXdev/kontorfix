<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $registration_enabled
 * @property list<string> $enabled_registry_types
 */
class SystemSetting extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_enabled',
        'enabled_registry_types',
    ];

    protected $attributes = [
        'registration_enabled' => false,
        'enabled_registry_types' => '["composer","npm","python"]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_enabled' => 'bool',
            'enabled_registry_types' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create());
    }
}
