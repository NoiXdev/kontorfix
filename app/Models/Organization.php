<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')
            ->logOnly(['name', 'slug', 'is_operator', 'portal_enabled'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * `portal_enabled` here answers "does this customer have a portal at all" — off means
     * /c/{slug} is 404. It is NOT `groups.portal_enabled`, which answers "does this registry
     * appear in that portal" and leaves the registry's /r/... endpoints untouched. The two
     * compose and neither is derived from the other: turning every registry off still leaves
     * an open portal showing an empty package list.
     */
    protected $fillable = ['name', 'slug', 'is_operator', 'portal_enabled', 'enabled_registry_types', 'oci_auto_create_repositories', 'notification_cadence', 'last_digest_sent_at'];

    protected $attributes = [
        'portal_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_operator' => 'bool',
            'portal_enabled' => 'bool',
            // Null = inherit the instance-wide set; otherwise a restriction within it.
            'enabled_registry_types' => 'array',
            // Three states, not two: null = inherit the instance-wide setting, true/false =
            // this organization's own answer. Eloquent's primitive casts return null
            // untouched, so `bool` here does NOT flatten the inherit state into false —
            // App\Services\Registry\OciSettings depends on that distinction surviving.
            'oci_auto_create_repositories' => 'bool',
            'last_digest_sent_at' => 'datetime',
        ];
    }

    /**
     * Users whose home organization this is.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Users granted additional access to this organization (excludes home members).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * Packages this organization owns. `organization_id` carries a `restrictOnDelete`
     * foreign key, so this check is the readable half of that constraint — see
     * OrganizationController::destroy().
     *
     * @return HasMany<Package, $this>
     */
    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    /**
     * @return HasMany<RegistryToken, $this>
     */
    public function registryTokens(): HasMany
    {
        return $this->hasMany(RegistryToken::class);
    }

    /**
     * @return HasMany<NotificationRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }
}
