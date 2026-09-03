<?php

namespace App\Models;

use App\Enums\SharedPackageRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $registration_enabled
 * @property list<string> $enabled_registry_types
 * @property SharedPackageRole $shared_package_role
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
        'shared_package_role',
    ];

    protected $attributes = [
        'registration_enabled' => false,
        'enabled_registry_types' => '["composer","npm","python"]',
        'shared_package_role' => 'super_admin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_enabled' => 'bool',
            'enabled_registry_types' => 'array',
            'shared_package_role' => SharedPackageRole::class,
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create());
    }
}
