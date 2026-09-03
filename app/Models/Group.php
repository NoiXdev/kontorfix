<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * A rename gives up the registry's frozen legacy address.
     *
     * `legacy_slug` is the one-segment /r/{slug} URL this registry answered before the
     * instance was upgraded to organization-scoped slugs; it was frozen at migration time
     * (2026_09_03_100000) and is never written by the application. Keeping it across a
     * rename would hand the registry a permanent alias, which is exactly the opposite of
     * the documented decision that changing a slug moves the address with no alias left
     * behind — and it would keep a name the operator has given up reserved instance-wide
     * against every other tenant.
     *
     * Lives on the model rather than in the two update paths (console and JSON API): the
     * column is an invariant of the row, not a concern of whoever happens to write it.
     *
     * This is also what keeps a non-NULL `legacy_slug` always equal to the row's own live
     * `slug`: it is only ever frozen from `slug` (at migration time) and only ever cleared,
     * never independently changed, so the two can never drift apart while `legacy_slug` is
     * set. That equality is load-bearing for `App\Rules\UnclaimedSlug`, which checks
     * `groups.slug` and never `legacy_slug` directly — a frozen legacy address is still
     * covered, because it can only ever equal the `slug` of the very row that is already
     * being checked.
     */
    protected static function booted(): void
    {
        static::updating(function (self $group): void {
            if ($group->isDirty('slug')) {
                $group->legacy_slug = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('registry')
            ->logOnly(['name', 'slug', 'public', 'portal_enabled', 'organization_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'public',
        'portal_enabled',
    ];

    protected $attributes = [
        'portal_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'public' => 'bool',
            'portal_enabled' => 'bool',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsToMany<Package, $this, GroupPackage>
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)
            ->using(GroupPackage::class)
            ->withPivot('version_constraint', 'available_until');
    }

    /**
     * The assignments that are actually in force: `available_until` makes an assignment
     * time-limited, and an expired row serves nothing.
     *
     * The one statement of that predicate. RegistryAccessService decides what a registry
     * serves with it, and App\Services\Package\SharedAssignment decides whether a name is
     * already taken with it — two questions that must never be able to disagree about
     * whether a given row counts.
     *
     * @return BelongsToMany<Package, $this, GroupPackage>
     */
    public function assignedPackages(): BelongsToMany
    {
        return $this->packages()->where(fn (Builder $q) => $q
            ->whereNull('group_package.available_until')
            ->orWhere('group_package.available_until', '>', now()));
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<RegistryToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(RegistryToken::class);
    }

    /**
     * @return HasMany<Upstream, $this>
     */
    public function upstreams(): HasMany
    {
        return $this->hasMany(Upstream::class);
    }
}
