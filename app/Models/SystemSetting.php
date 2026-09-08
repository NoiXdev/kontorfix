<?php

namespace App\Models;

use App\Enums\SharedPackageRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool $registration_enabled
 * @property list<string> $enabled_registry_types
 * @property SharedPackageRole $shared_package_role
 * @property bool $oci_auto_create_repositories
 * @property string|null $retention_policy_id
 * @property int $oci_blob_grace_hours
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
        'retention_policy_id',
        'oci_blob_grace_hours',
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
        // Unset, like the per-package column: a fresh installation removes no tag until an
        // operator picks a policy. Stated here as well as in the migration's column default
        // for the same reason the flag above is — `::current()` must create a truthful row
        // rather than one whose values are null until read back from the database.
        'retention_policy_id' => null,
        // Hours. The sweeper removes an unreachable blob only when it is older than this,
        // because a push uploads every blob first and writes the manifest last — in between
        // each fresh blob is unreachable and looks exactly like garbage. It therefore has to
        // outlast the longest realistic push.
        'oci_blob_grace_hours' => 24,
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
            'oci_blob_grace_hours' => 'int',
        ];
    }

    /**
     * The instance-wide default retention policy, or null when none is set.
     *
     * @return BelongsTo<RetentionPolicy, $this>
     */
    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class);
    }

    public static function current(): self
    {
        return static::query()->firstOr(fn () => static::query()->create());
    }
}
