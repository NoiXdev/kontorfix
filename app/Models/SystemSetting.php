<?php

namespace App\Models;

use App\Enums\SharedPackageRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $registration_enabled
 * @property list<string> $enabled_registry_types
 * @property SharedPackageRole $shared_package_role
 * @property bool $oci_auto_create_repositories
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
        'oci_auto_create_repositories',
    ];

    protected $attributes = [
        'registration_enabled' => false,
        'enabled_registry_types' => '["composer","npm","python","docker"]',
        'shared_package_role' => 'super_admin',
        // Off: the instance keeps refusing a `docker push` to a name nobody registered
        // until an operator opts in. Stated here as well as in the migration's column
        // default, so `::current()` creates a truthful row rather than one whose flag is
        // null until it has been read back from the database.
        'oci_auto_create_repositories' => false,
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
            'oci_auto_create_repositories' => 'bool',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create());
    }
}
