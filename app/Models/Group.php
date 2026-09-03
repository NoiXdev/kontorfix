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
     * serves with it, App\Services\Package\SharedAssignment decides whether a name is
     * already taken with it, and App\Http\Controllers\Registry\PypiController decides
     * through it which project a twine upload may target — questions that must never be able
     * to disagree about whether a given row counts.
     *
     * PREMISE, and it is load-bearing: nothing in the application writes `available_until`.
     * The column is created by a migration, declared on the pivot, and read here; no
     * controller, request, job or service sets it. So an expired assignment stays expired,
     * and SharedAssignment can safely permit what an expired row would otherwise have
     * blocked — assigning or creating a customer's own package under a name a lapsed shared
     * assignment used to serve. Its refusal at attach time is the only thing standing
     * between a registry and serving an own and a shared package under one name.
     *
     * WHOEVER ADDS AN `available_until` EDITOR MUST RUN SharedAssignment ON THAT WRITE.
     * Task 6 of the shared-packages plan surfaces this field in the assignment dialog (spec
     * §6). The moment an operator can push a lapsed shared assignment back into the future,
     * they can resurrect exactly the collision the attach guard refuses — through a write
     * that changes no pivot membership at all, and so passes no guard today. Extending an
     * assignment is an assignment: it has to ask SharedAssignment whether the name is free,
     * or the invariant holds on three write paths and not the fourth.
     *
     * That editor moves a fourth write besides those three: PypiController::upload() resolves
     * its target project through this relation and through nothing else — it never reaches
     * canAccessPackage() — so an `available_until` pushed back into the future reopens a
     * publish path, not only a read path. npm's equivalent runs through
     * RegistryAccessService::packageBelongsToGroup() and therefore through this relation too.
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
